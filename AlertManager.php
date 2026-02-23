<?php

/**
 * AlertManager.php — Route Tracker v2
 * Multi-channel alert sending (Email, Telegram, Viber, Signal)
 */

class AlertManager
{
    private Config $config;
    private string $logFile;
    private string $countFile;

    public function __construct(Config $config)
    {
        $this->config    = $config;
        $this->logFile   = $config->getBaseDir() . '/data/alerts.log';
        $this->countFile = $config->getBaseDir() . '/data/alert_counts.json';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Main evaluation entry point
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Evaluate traffic and send alerts if thresholds are exceeded.
     *
     * @param array      $route           Route definition from YAML
     * @param array      $schedEntry      Schedule entry (_schedule_mode, _scheduled_time)
     * @param int        $currentDuration Current primary route duration in seconds
     * @param int|null   $avgDuration     Historical average in seconds (null = not enough data)
     * @param array      $currentRoute    Primary route data (summary, distance_text, etc.)
     * @param array|null $bestAltRoute    Best alternative route data (or null)
     * @param int|null   $bestAltDuration Best alternative duration in seconds
     */
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
        $channels = $this->config->getRouteAlertChannels($route);

        if (empty($channels)) {
            return;
        }

        // ── 1. Heavy traffic alert ─────────────────────────────────────────
        if (
            $avgDuration !== null &&
            $avgDuration > 0 &&
            $currentDuration > $avgDuration * (1 + $settings['traffic_threshold_percent'] / 100)
        ) {
            if ($this->canSendAlert($route['id'])) {
                $pct  = round(($currentDuration - $avgDuration) / $avgDuration * 100);
                $msg  = $this->buildHeavyTrafficMessage(
                    $route, $schedEntry, $currentDuration, $avgDuration, $pct
                );
                $this->dispatch($channels, "🚗🔴 Heavy Traffic Alert", $msg, $route);
                $this->incrementAlertCount($route['id']);
            }
        }

        // ── 2. Better alternative alert ────────────────────────────────────
        if (
            $bestAltRoute !== null &&
            $bestAltDuration !== null &&
            ($currentDuration - $bestAltDuration) > 120   // > 2 minutes savings
        ) {
            if ($this->canSendAlert($route['id'])) {
                $msg = $this->buildBetterRouteMessage(
                    $route, $schedEntry, $currentDuration, $currentRoute,
                    $bestAltDuration, $bestAltRoute
                );
                $this->dispatch($channels, "🚗💡 Better Route Found", $msg, $route);
                $this->incrementAlertCount($route['id']);
            }
        }
    }

    /**
     * Send error alert for a route.
     */
    public function sendErrorAlert(array $route, string $errorMessage): void
    {
        $channels = $this->config->getRouteAlertChannels($route);
        if (empty($channels)) {
            return;
        }

        $msg = "⚠️ Route Tracker Error\n\n" .
               "Route: {$route['label']}\n" .
               "Error: {$errorMessage}\n" .
               "Time: " . date('Y-m-d H:i:s');

        $this->dispatch($channels, "⚠️ Route Tracker Error", $msg, $route);
    }

    /**
     * Send test message to all enabled channels for a route (or all routes).
     */
    public function sendTest(?string $routeId = null): void
    {
        $routes = $routeId
            ? array_filter([$this->config->getRoute($routeId)])
            : $this->config->getAllRoutes();

        foreach ($routes as $route) {
            $channels = $this->config->getRouteAlertChannels($route);
            if (empty($channels)) {
                echo "  Route {$route['id']}: no enabled alert channels\n";
                continue;
            }
            $msg = "🧪 Test Alert\n\n" .
                   "Route: {$route['label']}\n" .
                   "Channels: " . implode(', ', $channels) . "\n" .
                   "Time: " . date('Y-m-d H:i:s') . "\n\n" .
                   "If you receive this, alerts are working correctly.";

            echo "  Sending test to [" . implode(', ', $channels) . "] for: {$route['label']}\n";
            $this->dispatch($channels, "🧪 Route Tracker Test", $msg, $route);
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
        $curMin    = round($curDur / 60, 1);
        $altMin    = round($altDur / 60, 1);
        $savings   = round(($curDur - $altDur) / 60, 1);
        $curName   = $curRouteData['summary'] ?? 'current route';
        $altName   = $altRouteData['summary'] ?? 'alternative';

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

        // Prune old dates
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
    // Dispatch to channels
    // ──────────────────────────────────────────────────────────────────────────

    private function dispatch(array $channels, string $subject, string $body, array $route): void
    {
        foreach ($channels as $channel) {
            try {
                switch ($channel) {
                    case 'email':
                        $ok = $this->sendEmail($subject, $body);
                        break;
                    case 'telegram':
                        $ok = $this->sendTelegram($body);
                        break;
                    case 'viber':
                        $ok = $this->sendViber($body);
                        break;
                    case 'signal':
                        $ok = $this->sendSignal($body);
                        break;
                    default:
                        $ok = false;
                }
                $status = $ok ? 'OK' : 'FAIL';
            } catch (Exception $e) {
                $status = 'ERROR: ' . $e->getMessage();
            }
            $this->log("Alert [{$channel}] route={$route['id']} status={$status}");
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Email via SMTP or mail()
    // ──────────────────────────────────────────────────────────────────────────

    private function sendEmail(string $subject, string $body): bool
    {
        $cfg = $this->config->getAlertConfig('email');
        if (empty($cfg['enabled'])) return false;

        $recipients = $cfg['recipients'] ?? [];
        if (empty($recipients)) return false;

        $method = $cfg['method'] ?? 'smtp';

        if ($method === 'smtp') {
            return $this->sendSmtp($cfg, $subject, $body, $recipients);
        }

        // Fallback: PHP mail()
        $headers  = "From: {$cfg['from_name']} <{$cfg['from_address']}>\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $to = implode(', ', $recipients);
        return mail($to, $subject, $body, $headers);
    }

    /**
     * Raw SMTP via fsockopen + STARTTLS (no external libraries).
     */
    private function sendSmtp(array $cfg, string $subject, string $body, array $recipients): bool
    {
        $host       = $cfg['smtp_host']       ?? '';
        $port       = (int)($cfg['smtp_port'] ?? 587);
        $enc        = $cfg['smtp_encryption'] ?? 'tls';
        $user       = $cfg['smtp_username']   ?? '';
        $pass       = $cfg['smtp_password']   ?? '';
        $from       = $cfg['from_address']    ?? $user;
        $fromName   = $cfg['from_name']       ?? 'Route Tracker';

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

        $read(); // banner

        if ($enc === 'tls') {
            $send("EHLO localhost");
            $send("STARTTLS");
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        }

        $send("EHLO localhost");

        // AUTH LOGIN
        $send("AUTH LOGIN");
        $send(base64_encode($user));
        $send(base64_encode($pass));

        $send("MAIL FROM:<{$from}>");

        foreach ($recipients as $rcpt) {
            $send("RCPT TO:<{$rcpt}>");
        }

        $send("DATA");

        $date    = date('r');
        $to      = implode(', ', $recipients);
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
    // Telegram
    // ──────────────────────────────────────────────────────────────────────────

    private function sendTelegram(string $body): bool
    {
        $cfg = $this->config->getAlertConfig('telegram');
        if (empty($cfg['enabled'])) return false;

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
            $resp = $this->httpPost($url, $payload, ['Content-Type: application/json']);
            if (!$resp || !isset(json_decode($resp, true)['ok'])) {
                $ok = false;
            }
        }

        return $ok;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Viber
    // ──────────────────────────────────────────────────────────────────────────

    private function sendViber(string $body): bool
    {
        $cfg = $this->config->getAlertConfig('viber');
        if (empty($cfg['enabled'])) return false;

        $token       = $cfg['auth_token']    ?? '';
        $receiverIds = $cfg['receiver_ids']  ?? [];
        if (empty($token) || empty($receiverIds)) return false;

        $url = 'https://chatapi.viber.com/pa/send_message';
        $ok  = true;

        foreach ($receiverIds as $receiverId) {
            $payload = json_encode([
                'receiver' => $receiverId,
                'type'     => 'text',
                'text'     => $body,
            ]);
            $headers = [
                'Content-Type: application/json',
                "X-Viber-Auth-Token: {$token}",
            ];
            $resp = $this->httpPost($url, $payload, $headers);
            if (!$resp) {
                $ok = false;
            }
        }

        return $ok;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Signal (via signal-cli-rest-api)
    // ──────────────────────────────────────────────────────────────────────────

    private function sendSignal(string $body): bool
    {
        $cfg = $this->config->getAlertConfig('signal');
        if (empty($cfg['enabled'])) return false;

        $apiUrl     = rtrim($cfg['api_url'] ?? 'http://localhost:8080', '/');
        $sender     = $cfg['sender_number']       ?? '';
        $recipients = $cfg['recipient_numbers']   ?? [];
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
            CURLOPT_TIMEOUT        => 15,
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
