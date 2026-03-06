<?php

/**
 * DepartureAdvisor.php — Route Tracker v3
 * Core advisor class: checks live traffic, calculates recommended departure,
 * tracks alert stages, dispatches messages.
 */

class DepartureAdvisor
{
    private Config       $config;
    private PDO          $pdo;
    private AlertManager $alertMgr;
    private string       $logFile;

    public function __construct(Config $config, AlertManager $alertMgr)
    {
        $this->config   = $config;
        $this->pdo      = $config->getPdo();
        $this->alertMgr = $alertMgr;
        $this->logFile  = $config->getBaseDir() . '/data/advisor.log';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Buffer calculation
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Calculate buffer minutes based on historical standard deviation.
     */
    private function bufferMinutes(float $stddevSec): int
    {
        if ($stddevSec < 180) return 5;   // <3 min stddev → 5 min buffer
        if ($stddevSec < 480) return 10;  // <8 min stddev → 10 min buffer
        return 15;                         // ≥8 min stddev → 15 min buffer
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Stage detection
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Map current timing to an alert stage name (or null).
     *
     * @param int $minsToArrival    Minutes remaining until target arrival
     * @param int $minsToDeparture  Minutes remaining until recommended departure (may be negative)
     */
    private function currentStage(int $minsToArrival, int $minsToDeparture): ?string
    {
        if ($minsToArrival >= 60 && $minsToArrival <= 90)  return 'planning';
        if ($minsToArrival >= 30 && $minsToArrival < 60)   return 'window';
        if ($minsToDeparture >= 10 && $minsToDeparture <= 30) return 'reminder';
        if ($minsToDeparture >= 0  && $minsToDeparture < 10)  return 'urgent';
        if ($minsToDeparture >= -5 && $minsToDeparture < 0)   return 'last_call';
        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Main run method
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Run advisor check for one route + one schedule entry.
     *
     * @param array $route      Route row (decoded by Config::decodeRoute)
     * @param array $schedEntry One entry from route['schedule'], must have 'arrive' key
     */
    public function run(array $route, array $schedEntry): void
    {
        // Only advisor-mode runs for 'arrive' schedule entries
        if (!isset($schedEntry['arrive'])) {
            return;
        }

        $arriveTime = $schedEntry['arrive']; // "HH:MM"
        $days       = $this->config->parseDays($schedEntry['days'] ?? '');
        $today      = (int)date('N');         // ISO 1=Mon .. 7=Sun
        $todayDate  = date('Y-m-d');
        $now        = time();

        // Check if today is a scheduled day
        if (!in_array($today, $days, true)) {
            return;
        }

        // Calculate arrival time in minutes from midnight
        [$ah, $am] = explode(':', $arriveTime);
        $arrivalMinutes = (int)$ah * 60 + (int)$am;
        $currentMinutes = (int)date('H') * 60 + (int)date('i');

        $minsToArrival   = $arrivalMinutes - $currentMinutes;
        $startBeforeMin  = (int)($route['advisor_start_before'] ?? 90);

        // Only run within the advisor window (up to startBeforeMins before arrival,
        // and up to 15 mins after arrival for last_call)
        if ($minsToArrival > $startBeforeMin || $minsToArrival < -15) {
            return;
        }

        // ── Load historical stats for buffer calculation ──────────────────
        $histStats = $this->getHistoricalStats($route['id'], $today, $arriveTime);
        $avgSec    = $histStats['avg'] ?? null;
        $stddevSec = $histStats['stddev'] ?? 0;

        // ── Calculate buffer ──────────────────────────────────────────────
        if ($route['advisor_buffer_mode'] === 'fixed') {
            $bufferMin = (int)($route['advisor_fixed_buffer'] ?? 10);
        } else {
            $bufferMin = $this->bufferMinutes((float)$stddevSec);
        }

        // ── Call Google Maps API for live duration ────────────────────────
        $apiResult = $this->fetchLiveDuration($route);
        if ($apiResult === null) {
            $this->alog("Advisor: API call failed for {$route['id']}");
            return;
        }

        $liveSec        = $apiResult['duration_seconds'];
        $liveMin        = (int)ceil($liveSec / 60);
        $liveSummary    = $apiResult['summary'] ?? '';
        $origin         = $route['origin'];
        $destination    = $route['destination'];

        // ── Calculate recommended departure ──────────────────────────────
        $recommendedMin  = $arrivalMinutes - $liveMin - $bufferMin;
        $minsToDeparture = $recommendedMin - $currentMinutes;

        $recHour = (int)floor($recommendedMin / 60);
        $recMin  = $recommendedMin % 60;
        if ($recMin < 0) { $recHour--; $recMin += 60; }
        $recommendedDepart = sprintf('%02d:%02d', ($recHour + 24) % 24, ($recMin + 60) % 60);

        // ── Determine current stage ───────────────────────────────────────
        $stage = $this->currentStage($minsToArrival, $minsToDeparture);
        if ($stage === null) {
            return;
        }

        // ── Check if stage is in route's enabled stages ───────────────────
        $enabledStages = $route['advisor_stages'] ?? ['planning','window','reminder','urgent','last_call'];
        if (!in_array($stage, $enabledStages, true)) {
            return;
        }

        // ── Load state and check if this stage already fired today ────────
        $schedKey = $arriveTime . '_arrive';
        $state    = $this->loadState($route['id'], $schedKey, $todayDate);
        $fired    = $state['stages_fired'] ?? [];

        if (in_array($stage, $fired, true)) {
            return;
        }

        // ── Generate monitoring URL (if app_url is configured) ────────────
        $monitorUrl = '';
        $appUrl     = rtrim($this->config->getSetting('app_url', ''), '/');
        if ($appUrl) {
            $token      = $this->config->createOrGetMonitoringToken(
                $route['id'], $schedKey, $todayDate, $arriveTime, $route['label']
            );
            $monitorUrl = $appUrl . '/monitor.php?token=' . $token;
        }

        // ── Build alert message ───────────────────────────────────────────
        $gmapsUrl = "https://www.google.com/maps/dir/?api=1" .
                    "&origin=" . urlencode($origin) .
                    "&destination=" . urlencode($destination) .
                    "&travelmode=driving";

        $vsAvg = '';
        if ($avgSec !== null && $avgSec > 0) {
            $pct = round(($liveSec - $avgSec) / $avgSec * 100);
            if ($pct > 0) {
                $vsAvg = " (+{$pct}% vs avg)";
            } elseif ($pct < 0) {
                $vsAvg = " ({$pct}% vs avg)";
            }
        }

        $msg = $this->buildStageMessage(
            $stage, $route['label'], $arriveTime, $recommendedDepart,
            $liveMin, $liveSummary, $minsToDeparture, $bufferMin,
            $vsAvg, $gmapsUrl, $monitorUrl
        );

        $subject = $this->stageSubject($stage, $route['label']);

        // ── Dispatch alert ────────────────────────────────────────────────
        $this->alertMgr->sendToRoute($route, $subject, $msg);

        // ── Update state ──────────────────────────────────────────────────
        $fired[] = $stage;
        $this->saveState($route['id'], $schedKey, $todayDate, [
            'stages_fired'          => $fired,
            'recommended_departure' => $recommendedDepart,
            'live_duration_seconds' => $liveSec,
            'last_check'            => date('Y-m-d H:i:s'),
        ]);

        $this->alog("Advisor: [{$stage}] {$route['id']} → leave {$recommendedDepart} (live={$liveMin}min{$vsAvg})");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Alert message builders
    // ──────────────────────────────────────────────────────────────────────────

    private function stageSubject(string $stage, string $routeLabel): string
    {
        switch ($stage) {
            case 'planning':  return "📋 Departure Planning — {$routeLabel}";
            case 'window':    return "🕐 Departure Window — {$routeLabel}";
            case 'reminder':  return "⏰ Departure Reminder — {$routeLabel}";
            case 'urgent':    return "⚠️ Leave Soon! — {$routeLabel}";
            case 'last_call': return "🚨 Last Call! — {$routeLabel}";
            default:          return "🚗 Route Advisor — {$routeLabel}";
        }
    }

    private function buildStageMessage(
        string $stage, string $routeLabel, string $arriveTime,
        string $recommendedDepart, int $liveMin, string $liveSummary,
        int $minsToDeparture, int $bufferMin, string $vsAvg,
        string $gmapsUrl, string $monitorUrl = ''
    ): string {
        $via     = $liveSummary ? " via {$liveSummary}" : '';
        $monitor = $monitorUrl ? "\n📊 {$monitorUrl}" : '';

        switch ($stage) {
            case 'planning':
                return "📋 Departure Planning\n\n" .
                       "Route: {$routeLabel}\n" .
                       "Target arrival: {$arriveTime}\n" .
                       "Traffic looks normal. Leave by {$recommendedDepart} to arrive by {$arriveTime}{$via} ({$liveMin} min{$vsAvg}).\n" .
                       "Buffer: {$bufferMin} min\n\n" .
                       "🧭 {$gmapsUrl}{$monitor}";

            case 'window':
                return "🕐 Departure Window\n\n" .
                       "Route: {$routeLabel}\n" .
                       "Target arrival: {$arriveTime}\n" .
                       "Recommended departure: {$recommendedDepart}\n" .
                       "Live estimate: {$liveMin} min{$via}{$vsAvg}\n\n" .
                       "🧭 {$gmapsUrl}{$monitor}";

            case 'reminder':
                $inMin = $minsToDeparture > 0 ? "Leave in {$minsToDeparture} min (by {$recommendedDepart})" : "Leave now!";
                return "⏰ Departure Reminder\n\n" .
                       "Route: {$routeLabel}\n" .
                       "{$inMin} for {$arriveTime} arrival. ({$liveMin} min{$via}{$vsAvg})\n\n" .
                       "🧭 {$gmapsUrl}{$monitor}";

            case 'urgent':
                $inMin = max(0, $minsToDeparture);
                return "⚠️ Leave in {$inMin} min!\n\n" .
                       "Route: {$routeLabel}\n" .
                       "Traffic building — {$liveMin} min{$via}{$vsAvg}\n" .
                       "Target arrival: {$arriveTime}\n\n" .
                       "🧭 {$gmapsUrl}{$monitor}";

            case 'last_call':
                return "🚨 Departure time!\n\n" .
                       "Route: {$routeLabel}\n" .
                       "Best estimate: {$liveMin} min{$via}{$vsAvg}\n" .
                       "Target arrival: {$arriveTime}\n\n" .
                       "🧭 {$gmapsUrl}{$monitor}";

            default:
                return "🚗 Route Advisor\n\nRoute: {$routeLabel}\nLeave by {$recommendedDepart}.";
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Google Maps API call
    // ──────────────────────────────────────────────────────────────────────────

    private function fetchLiveDuration(array $route): ?array
    {
        $params = [
            'origin'         => $route['origin'],
            'destination'    => $route['destination'],
            'mode'           => $route['travel_mode'] ?? 'driving',
            'departure_time' => 'now',
            'language'       => $this->config->getSetting('google_maps_language', 'el'),
            'region'         => $this->config->getSetting('google_maps_region', 'gr'),
            'key'            => $this->config->getApiKey(),
        ];

        $url      = 'https://maps.googleapis.com/maps/api/directions/json?' . http_build_query($params);
        $response = $this->callApi($url);

        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (($data['status'] ?? '') !== 'OK' || empty($data['routes'])) {
            return null;
        }

        $primary = $data['routes'][0];
        $leg     = $primary['legs'][0] ?? [];

        return [
            'duration_seconds' => $leg['duration_in_traffic']['value'] ?? ($leg['duration']['value'] ?? 0),
            'summary'          => $primary['summary'] ?? '',
        ];
    }

    private function callApi(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp  = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        return ($error || !$resp) ? null : $resp;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Historical stats
    // ──────────────────────────────────────────────────────────────────────────

    private function getHistoricalStats(string $routeId, int $day, string $schedTime): array
    {
        $st = $this->pdo->prepare("
            SELECT AVG(traffic_duration_seconds)    AS avg_dur,
                   COUNT(*)                         AS samples,
                   -- SQLite doesn't have STDDEV; compute manually
                   AVG(traffic_duration_seconds * traffic_duration_seconds) AS avg_sq
            FROM trips
            WHERE route_id       = :route_id
              AND scheduled_day  = :day
              AND scheduled_time = :sched_time
              AND api_status     = 'OK'
        ");
        $st->execute([':route_id' => $routeId, ':day' => $day, ':sched_time' => $schedTime]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int)$row['samples'] < 3) {
            return [];
        }

        $avg    = (float)$row['avg_dur'];
        $avgSq  = (float)$row['avg_sq'];
        $stddev = sqrt(max(0, $avgSq - $avg * $avg));

        return [
            'avg'     => (int)round($avg),
            'stddev'  => (float)$stddev,
            'samples' => (int)$row['samples'],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Advisor state (DB-backed)
    // ──────────────────────────────────────────────────────────────────────────

    private function loadState(string $routeId, string $schedKey, string $date): array
    {
        $st = $this->pdo->prepare("
            SELECT * FROM advisor_state
            WHERE route_id = :rid AND schedule_key = :sk AND date = :d
        ");
        $st->execute([':rid' => $routeId, ':sk' => $schedKey, ':d' => $date]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['stages_fired' => []];
        }

        $row['stages_fired'] = json_decode($row['stages_fired'] ?? '[]', true) ?: [];
        return $row;
    }

    private function saveState(string $routeId, string $schedKey, string $date, array $data): void
    {
        $st = $this->pdo->prepare("
            INSERT OR REPLACE INTO advisor_state
                (route_id, schedule_key, date, stages_fired, recommended_departure,
                 live_duration_seconds, last_check)
            VALUES
                (:rid, :sk, :d, :fired, :rec_dep, :live_dur, :last_chk)
        ");
        $st->execute([
            ':rid'      => $routeId,
            ':sk'       => $schedKey,
            ':d'        => $date,
            ':fired'    => json_encode($data['stages_fired']),
            ':rec_dep'  => $data['recommended_departure'] ?? null,
            ':live_dur' => $data['live_duration_seconds'] ?? null,
            ':last_chk' => $data['last_check'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Get current advisor status for all advisor-enabled routes (for dashboard)
    // ──────────────────────────────────────────────────────────────────────────

    public function getStatus(): array
    {
        $routes = $this->config->getAllActiveRoutes();
        $today  = date('Y-m-d');
        $result = [];

        foreach ($routes as $route) {
            if (empty($route['advisor_enabled'])) {
                continue;
            }

            foreach ($route['schedule'] ?? [] as $sched) {
                if (!isset($sched['arrive'])) {
                    continue;
                }

                $arriveTime = $sched['arrive'];
                $schedKey   = $arriveTime . '_arrive';
                $days       = $this->config->parseDays($sched['days'] ?? '');

                $state = $this->loadState($route['id'], $schedKey, $today);

                [$ah, $am] = explode(':', $arriveTime);
                $arrivalMinutes = (int)$ah * 60 + (int)$am;
                $currentMinutes = (int)date('H') * 60 + (int)date('i');

                $recDep    = $state['recommended_departure'] ?? null;
                $recDepMin = null;
                $untilDepart = null;

                if ($recDep) {
                    [$rh, $rm] = explode(':', $recDep);
                    $recDepMin   = (int)$rh * 60 + (int)$rm;
                    $untilDepart = $recDepMin - $currentMinutes;
                }

                $monitorUrl = $this->config->getMonitoringUrl($route['id'], $schedKey, $today);

                $result[] = [
                    'route_id'              => $route['id'],
                    'route_label'           => $route['label'],
                    'origin'                => $route['origin'],
                    'destination'           => $route['destination'],
                    'arrive_time'           => $arriveTime,
                    'days'                  => $sched['days'] ?? '',
                    'recommended_departure' => $recDep,
                    'live_duration_seconds' => $state['live_duration_seconds'] ?? null,
                    'stages_fired'          => $state['stages_fired'] ?? [],
                    'enabled_stages'        => $route['advisor_stages'],
                    'until_departure_min'   => $untilDepart,
                    'until_arrival_min'     => $arrivalMinutes - $currentMinutes,
                    'last_check'            => $state['last_check'] ?? null,
                    'buffer_mode'           => $route['advisor_buffer_mode'],
                    'fixed_buffer'          => $route['advisor_fixed_buffer'],
                    'monitor_url'           => $monitorUrl,
                ];
            }
        }

        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Logging
    // ──────────────────────────────────────────────────────────────────────────

    private function alog(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        echo $line;
    }
}
