<?php

/**
 * AlertManager.php — Route Tracker v3
 * Multi-channel alert sending via named channel profiles and alert profiles.
 *
 * Alert flow:
 *   route → alert_profile_ids[] → alert_profiles.channels[] → channel_profiles → send
 */

class AlertManager
{
    private Config $config;
    private string $logFile;
    private string $countFile;

    public function __construct(Config $config)
    {
        $this->config    = $config;
        $dataDir         = $config->getBaseDir() . '/' . Config::deploy('data_dir');
        $this->logFile   = $dataDir . '/alerts.log';
        $this->countFile = $dataDir . '/alert_counts.json';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Main evaluation entry point
    // ──────────────────────────────────────────────────────────────────────────

    public function evaluateAndAlert(
        array  $route,
        array  $schedEntry,
        int    $currentDuration,
        ?int   $avgDuration,
        array  $currentRoute,
        ?array $bestAltRoute    = null,
        ?int   $bestAltDuration = null
    ): void {
        $settings = $this->config->getAlertSettings();
        $profiles = $this->config->getRouteAlertProfiles($route);

        if (empty($profiles)) {
            return;
        }

        // ── 1. Heavy traffic alert ─────────────────────────────────────────
        if (
            $avgDuration !== null &&
            $avgDuration > 0 &&
            $currentDuration > $avgDuration * (1 + $settings['traffic_threshold_percent'] / 100)
        ) {
            if ($this->canSendAlert($route['id'])) {
                $pct = round(($currentDuration - $avgDuration) / $avgDuration * 100);
                $msg = $this->buildHeavyTrafficMessage(
                    $route, $schedEntry, $currentDuration, $avgDuration, $pct
                );
                foreach ($profiles as $profile) {
                    $this->dispatchProfile($profile, "🚗🔴 Heavy Traffic Alert", $msg, $route);
                }
                $this->incrementAlertCount($route['id']);
            }
        }

        // ── 2. Better alternative alert ────────────────────────────────────
        if (
            $bestAltRoute !== null &&
            $bestAltDuration !== null &&
            ($currentDuration - $bestAltDuration) > 120  // > 2 minutes savings
        ) {
            if ($this->canSendAlert($route['id'])) {
                $msg = $this->buildBetterRouteMessage(
                    $route, $schedEntry, $currentDuration, $currentRoute,
                    $bestAltDuration, $bestAltRoute
                );
                foreach ($profiles as $profile) {
                    $this->dispatchProfile($profile, "🚗💡 Better Route Found", $msg, $route);
                }
                $this->incrementAlertCount($route['id']);
            }
        }
    }

    /**
     * Send error alert for a route.
     */
    public function sendErrorAlert(array $route, string $errorMessage): void
    {
        $profiles = $this->config->getRouteAlertProfiles($route);
        if (empty($profiles)) {
            return;
        }

        $msg = "⚠️ Route Tracker Error\n\n" .
               "Route: {$route['label']}\n" .
               "Error: {$errorMessage}\n" .
               "Time: " . date('Y-m-d H:i:s');

        foreach ($profiles as $profile) {
            $this->dispatchProfile($profile, "⚠️ Route Tracker Error", $msg, $route);
        }
    }

    /**
     * Send a raw message to a specific route's alert profiles (used by advisor).
     */
    public function sendToRoute(array $route, string $subject, string $body): void
    {
        $profiles = $this->config->getRouteAlertProfiles($route);
        if (empty($profiles)) {
            return;
        }
        foreach ($profiles as $profile) {
            $this->dispatchProfile($profile, $subject, $body, $route);
        }
    }

    /**
     * Send test message via all profiles for a route (or all routes).
     */
    public function sendTest(?string $routeId = null): void
    {
        $routes = $routeId
            ? array_filter([$this->config->getRoute($routeId)])
            : $this->config->getAllActiveRoutes();

        foreach ($routes as $route) {
            $profiles = $this->config->getRouteAlertProfiles($route);
            if (empty($profiles)) {
                echo "  Route {$route['id']}: no enabled alert profiles assigned\n";
                continue;
            }

            $msg = "🧪 Test Alert\n\n" .
                   "Route: {$route['label']}\n" .
                   "Profiles: " . implode(', ', array_column($profiles, 'id')) . "\n" .
                   "Time: " . date('Y-m-d H:i:s') . "\n\n" .
                   "If you receive this, alerts are working correctly.";

            foreach ($profiles as $profile) {
                echo "  Dispatching via profile '{$profile['id']}' for route: {$route['label']}\n";
                $this->dispatchProfile($profile, "🧪 Route Tracker Test", $msg, $route);
            }
        }
    }

    /**
     * Send test message via all channels in one alert profile.
     * Returns ['ok' => bool, 'message' => string].
     */
    public function sendTestAlertProfile(string $profileId): array
    {
        $profile = $this->config->getAlertProfile($profileId);
        if (!$profile) {
            return ['ok' => false, 'message' => "Alert profile '{$profileId}' not found or disabled."];
        }

        $msg = "🧪 Route Tracker — Test Alert\n\n" .
               "Profile: {$profile['label']}\n" .
               "Time: " . date('Y-m-d H:i:s') . "\n\n" .
               "If you see this, the alert profile is configured correctly.";

        try {
            $this->dispatchProfile(
                $profile,
                "🧪 Route Tracker Test",
                $msg,
                ['id' => $profileId, 'label' => $profile['label']]
            );
            return ['ok' => true, 'message' => 'Test dispatched via all channel bindings.'];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send test message via one specific channel profile.
     * Returns ['ok' => bool, 'message' => string].
     */
    public function sendTestChannelProfile(string $type, string $profileId): array
    {
        $cfg = null;
        switch ($type) {
            case 'telegram': $cfg = $this->config->getTelegramProfile($profileId); break;
            case 'email':    $cfg = $this->config->getEmailProfile($profileId);    break;
            case 'signal':   $cfg = $this->config->getSignalProfile($profileId);   break;
            case 'viber':    $cfg = $this->config->getViberProfile($profileId);    break;
            default:
                return ['ok' => false, 'message' => "Unknown channel type: {$type}"];
        }

        if (!$cfg) {
            return ['ok' => false, 'message' => "Profile '{$profileId}' ({$type}) not found or disabled."];
        }

        $body = "🧪 Route Tracker — Channel Profile Test\n\n" .
                "Type: {$type}\nProfile: {$profileId}\n" .
                "Time: " . date('Y-m-d H:i:s') . "\n\n" .
                "If you see this, the channel profile is configured correctly.";

        try {
            $ok = false;
            switch ($type) {
                case 'telegram': $ok = $this->sendTelegram($cfg, $body); break;
                case 'email':    $ok = $this->sendEmail($cfg, "🧪 Route Tracker Test", $body); break;
                case 'signal':   $ok = $this->sendSignal($cfg, $body); break;
                case 'viber':    $ok = $this->sendViber($cfg, $body); break;
            }
            return ['ok' => $ok, 'message' => $ok ? 'Test message sent.' : 'Send failed — check alerts.log.'];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Message builders
    // ──────────────────────────────────────────────────────────────────────────

    private function buildHeavyTrafficMessage(
        array $route, array $sched, int $cur, int $avg, int $pct
    ): string {
        $curMin = round($cur / 60, 1);
        $avgMin = round($avg / 60, 1);
        $time   = $sched['_scheduled_time'] ?? '';
        $mode   = $sched['_schedule_mode']  ?? 'depart';

        return "🚗🔴 Heavy Traffic Alert!\n\n" .
               "Route: {$route['label']}\n" .
               "Scheduled: {$mode} {$time}\n" .
               "Current: {$curMin} min (+{$pct}% above normal)\n" .
               "Average: {$avgMin} min\n" .
               "Time: " . date('H:i') . "\n\n" .
               "Consider leaving earlier or using an alternative route.";
    }

    private function buildBetterRouteMessage(
        array $route, array $sched, int $curDur, array $curRouteData,
        int $altDur, array $altRouteData
    ): string {
        $curMin  = round($curDur / 60, 1);
        $altMin  = round($altDur / 60, 1);
        $savings = round(($curDur - $altDur) / 60, 1);
        $curName = $curRouteData['summary'] ?? 'current route';
        $altName = $altRouteData['summary'] ?? 'alternative';

        return "🚗💡 Better Route Found!\n\n" .
               "Route: {$route['label']}\n" .
               "Current route ({$curName}): {$curMin} min\n" .
               "Better route ({$altName}): {$altMin} min\n" .
               "Savings: {$savings} min\n" .
               "Time: " . date('H:i');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Rate limiting
    // ──────────────────────────────────────────────────────────────────────────

    private function canSendAlert(string $routeId): bool
    {
        $settings = $this->config->getAlertSettings();
        $max      = $settings['max_alerts_per_day'] ?? 3;
        $counts   = $this->loadAlertCounts();
        $today    = date('Y-m-d');
        return ($counts[$today][$routeId] ?? 0) < $max;
    }

    private function incrementAlertCount(string $routeId): void
    {
        $counts = $this->loadAlertCounts();
        $today  = date('Y-m-d');

        foreach (array_keys($counts) as $d) {
            if ($d !== $today) unset($counts[$d]);
        }

        $counts[$today][$routeId] = ($counts[$today][$routeId] ?? 0) + 1;
        file_put_contents($this->countFile, json_encode($counts, JSON_PRETTY_PRINT));
    }

    private function loadAlertCounts(): array
    {
        if (!file_exists($this->countFile)) return [];
        $data = json_decode(file_get_contents($this->countFile), true);
        return is_array($data) ? $data : [];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Profile dispatch
    // ──────────────────────────────────────────────────────────────────────────

    private function dispatchProfile(array $profile, string $subject, string $body, array $route): void
    {
        foreach ($profile['channels'] as $binding) {
            $type      = $binding['type']       ?? '';
            $profileId = $binding['profile_id'] ?? '';

            $cfg = null;
            switch ($type) {
                case 'telegram': $cfg = $this->config->getTelegramProfile($profileId); break;
                case 'email':    $cfg = $this->config->getEmailProfile($profileId);    break;
                case 'signal':   $cfg = $this->config->getSignalProfile($profileId);   break;
                case 'viber':    $cfg = $this->config->getViberProfile($profileId);    break;
            }

            if (!$cfg) {
                $this->log("Alert skip: {$type} profile '{$profileId}' not found or disabled (route={$route['id']})");
                continue;
            }

            try {
                $ok = false;
                switch ($type) {
                    case 'telegram': $ok = $this->sendTelegram($cfg, $body); break;
                    case 'email':    $ok = $this->sendEmail($cfg, $subject, $body); break;
                    case 'signal':   $ok = $this->sendSignal($cfg, $body); break;
                    case 'viber':    $ok = $this->sendViber($cfg, $body); break;
                }
                $status = $ok ? 'OK' : 'FAIL';
            } catch (Exception $e) {
                $status = 'ERROR: ' . $e->getMessage();
            }

            $this->log("Alert [{$type}:{$profileId}] route={$route['id']} status={$status}");
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Channel send methods (profile-cfg based)
    // ──────────────────────────────────────────────────────────────────────────

    private function sendTelegram(array $cfg, string $body): bool
    {
        $token   = $cfg['bot_token'] ?? '';
        $chatIds = $cfg['chat_ids']  ?? [];
        if (empty($token) || empty($chatIds)) return false;

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $ok  = true;

        foreach ($chatIds as $chatId) {
            $payload = json_encode([
                'chat_id'    => $chatId,
                'text'       => $body,
                'parse_mode' => 'HTML',
            ]);
            $resp   = $this->httpPost($url, $payload, ['Content-Type: application/json']);
            $parsed = $resp ? json_decode($resp, true) : null;
            // Fix: check the actual boolean value of 'ok', not just its existence
            if (!$parsed || !($parsed['ok'] ?? false)) {
                $ok = false;
            }
        }

        return $ok;
    }

    private function sendEmail(array $cfg, string $subject, string $body): bool
    {
        $recipients = $cfg['recipients'] ?? [];
        if (empty($recipients)) return false;

        return $this->sendSmtp($cfg, $subject, $body, $recipients);
    }

    private function sendViber(array $cfg, string $body): bool
    {
        $token       = $cfg['auth_token']   ?? '';
        $receiverIds = $cfg['receiver_ids'] ?? [];
        if (empty($token) || empty($receiverIds)) return false;

        $url = 'https://chatapi.viber.com/pa/send_message';
        $ok  = true;

        foreach ($receiverIds as $receiverId) {
            $payload = json_encode([
                'receiver' => $receiverId,
                'type'     => 'text',
                'text'     => $body,
            ]);
            $resp = $this->httpPost($url, $payload, [
                'Content-Type: application/json',
                "X-Viber-Auth-Token: {$token}",
            ]);
            if (!$resp) {
                $ok = false;
            }
        }

        return $ok;
    }

    private function sendSignal(array $cfg, string $body): bool
    {
        $apiUrl     = rtrim($cfg['api_url']            ?? 'http://localhost:8080', '/');
        $sender     = $cfg['sender_number']     ?? '';
        $recipients = $cfg['recipient_numbers'] ?? [];
        if (empty($sender) || empty($recipients)) return false;

        $payload = json_encode([
            'message'    => $body,
            'number'     => $sender,
            'recipients' => $recipients,
        ]);

        $resp = $this->httpPost("{$apiUrl}/v2/send", $payload, ['Content-Type: application/json']);
        return (bool)$resp;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SMTP implementation
    // ──────────────────────────────────────────────────────────────────────────

    private function sendSmtp(array $cfg, string $subject, string $body, array $recipients): bool
    {
        $host     = $cfg['smtp_host']       ?? '';
        $port     = (int)($cfg['smtp_port'] ?? 587);
        $enc      = $cfg['smtp_encryption'] ?? 'tls';
        $user     = $cfg['smtp_user']       ?? '';
        $pass     = $cfg['smtp_pass']       ?? '';
        $from     = $cfg['from_address']    ?? $user;
        $fromName = $cfg['from_name']       ?? 'Route Tracker';

        if ($enc === 'ssl') {
            $host = 'ssl://' . $host;
        }

        $errno = 0; $errstr = '';
        $sock = @fsockopen($host, $port, $errno, $errstr, 30);
        if (!$sock) {
            throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }

        $read = fn() => fgets($sock, 512);
        $send = function(string $cmd) use ($sock, &$read): string {
            fwrite($sock, $cmd . "\r\n");
            return $read();
        };

        $read();

        if ($enc === 'tls') {
            $send("EHLO localhost");
            $send("STARTTLS");
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        }

        $send("EHLO localhost");
        $send("AUTH LOGIN");
        $send(base64_encode($user));
        $send(base64_encode($pass));
        $send("MAIL FROM:<{$from}>");

        foreach ($recipients as $rcpt) {
            $send("RCPT TO:<{$rcpt}>");
        }

        $send("DATA");

        $date           = date('r');
        $to             = implode(', ', $recipients);
        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromEncoded    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

        $msg  = "Date: {$date}\r\n";
        $msg .= "From: {$fromEncoded} <{$from}>\r\n";
        $msg .= "To: {$to}\r\n";
        $msg .= "Subject: {$subjectEncoded}\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "\r\n";
        $msg .= chunk_split(base64_encode($body));
        $msg .= "\r\n.";

        $resp = $send($msg);
        $send("QUIT");
        fclose($sock);

        return str_starts_with(trim($resp), '2');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HTTP helper
    // ──────────────────────────────────────────────────────────────────────────

    private function httpPost(string $url, string $body, array $headers = []): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => Config::deploy('curl_timeout_alerts', 15),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp  = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->log("HTTP POST error: {$error}");
            return null;
        }
        return $resp ?: null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Logging
    // ──────────────────────────────────────────────────────────────────────────

    private function log(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        echo $line;
    }
}
