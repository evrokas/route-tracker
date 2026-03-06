<?php

/**
 * Config.php — Route Tracker v3
 * SQLite-backed configuration loader. Replaces YAML-based v2 loader.
 *
 * All settings come from the `settings` table; routes from the `routes` table.
 * Alert channel credentials live in per-type profile tables.
 */

class Config
{
    private static ?Config $instance = null;
    private string $baseDir;
    private PDO    $pdo;

    /** @var array<string,string> Settings cache (key → value) */
    private array $settings = [];

    /** @var array[]|null Routes cache */
    private ?array $routesCache = null;

    // Day name → ISO day number (1=Mon .. 7=Sun)
    private const DAY_MAP = [
        'mon' => 1, 'tue' => 2, 'wed' => 3,
        'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
    ];

    private function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/');
        $this->openDb();
        $this->loadSettings();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Singleton
    // ──────────────────────────────────────────────────────────────────────────

    public static function load(string $baseDir): self
    {
        if (self::$instance === null || self::$instance->baseDir !== $baseDir) {
            self::$instance = new self($baseDir);
        }
        return self::$instance;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Config not yet initialised. Call Config::load($dir) first.');
        }
        return self::$instance;
    }

    /** Reset singleton (useful for testing or after DB re-init). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Internal loading
    // ──────────────────────────────────────────────────────────────────────────

    private function openDb(): void
    {
        $dbPath = $this->getDbPath();
        $dbDir  = dirname($dbPath);

        if (!is_dir($dbDir)) {
            throw new RuntimeException(
                "Data directory does not exist: {$dbDir}\n" .
                "Run: php schema.php --init"
            );
        }

        if (!file_exists($dbPath)) {
            throw new RuntimeException(
                "Database not found: {$dbPath}\n" .
                "Run: php schema.php --init"
            );
        }

        $this->pdo = new PDO("sqlite:{$dbPath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode=WAL;');
        $this->pdo->exec('PRAGMA foreign_keys=ON;');
    }

    private function loadSettings(): void
    {
        try {
            $rows = $this->pdo->query("SELECT key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->settings = is_array($rows) ? $rows : [];
        } catch (Exception $e) {
            throw new RuntimeException(
                "Could not read settings table. Run: php schema.php --init\n(" . $e->getMessage() . ")"
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Generic dot-notation getter (reads from settings table)
    // ──────────────────────────────────────────────────────────────────────────

    public function get(string $dotKey, $default = null)
    {
        $flat = str_replace('.', '_', $dotKey);

        if (array_key_exists($flat, $this->settings)) {
            return $this->settings[$flat];
        }
        if (array_key_exists($dotKey, $this->settings)) {
            return $this->settings[$dotKey];
        }

        return $default;
    }

    public function getSetting(string $key, string $default = ''): string
    {
        return (string)($this->settings[$key] ?? $default);
    }

    public function setSetting(string $key, string $value): void
    {
        $st = $this->pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)");
        $st->execute([':key' => $key, ':value' => $value]);
        $this->settings[$key] = $value;
    }

    public function setSettings(array $pairs): void
    {
        $st = $this->pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)");
        $this->pdo->beginTransaction();
        try {
            foreach ($pairs as $key => $value) {
                $st->execute([':key' => $key, ':value' => (string)$value]);
                $this->settings[$key] = (string)$value;
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Convenience getters
    // ──────────────────────────────────────────────────────────────────────────

    public function getApiKey(): string
    {
        return $this->getSetting('google_maps_api_key');
    }

    /** @deprecated Legacy; kept for compatibility. Always empty in v3. */
    public function getApiToken(): string
    {
        return '';
    }

    public function getDashboardPasswordHash(): string
    {
        return $this->getSetting('dashboard_password_hash');
    }

    /** @deprecated Use getDashboardPasswordHash(). Kept for login.php compatibility. */
    public function getDashboardPassword(): string
    {
        return $this->getDashboardPasswordHash();
    }

    public function getDbPath(): string
    {
        return $this->baseDir . '/data/routes.sqlite';
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getTimezone(): string
    {
        return $this->getSetting('timezone', 'Europe/Athens');
    }

    public function getCollectionWindowBefore(): int
    {
        return (int)$this->getSetting('window_before_minutes', '15');
    }

    public function getCollectionWindowAfter(): int
    {
        return (int)$this->getSetting('window_after_minutes', '5');
    }

    public function requestAlternatives(): bool
    {
        return (bool)(int)$this->getSetting('request_alternatives', '1');
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Route getters
    // ──────────────────────────────────────────────────────────────────────────

    public function getAllRoutes(): array
    {
        if ($this->routesCache !== null) {
            return $this->routesCache;
        }

        try {
            $rows = $this->pdo->query("SELECT * FROM routes ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }

        $this->routesCache = array_map([$this, 'decodeRoute'], $rows);
        return $this->routesCache;
    }

    public function getAllActiveRoutes(): array
    {
        try {
            $rows = $this->pdo->query("SELECT * FROM routes WHERE active = 1 ORDER BY id")
                              ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
        return array_map([$this, 'decodeRoute'], $rows);
    }

    public function getRoute(string $id): ?array
    {
        foreach ($this->getAllRoutes() as $route) {
            if ($route['id'] === $id) {
                return $route;
            }
        }
        return null;
    }

    /** Decode JSON fields and normalise a route DB row. */
    private function decodeRoute(array $row): array
    {
        $row['schedule']          = json_decode($row['schedule']          ?? '[]', true) ?: [];
        $row['advisor_stages']    = json_decode($row['advisor_stages']    ?? '[]', true) ?: [];
        $row['alert_profile_ids'] = json_decode($row['alert_profile_ids'] ?? '[]', true) ?: [];
        $row['advisor_enabled']   = (bool)(int)($row['advisor_enabled']   ?? 0);
        $row['active']            = (bool)(int)($row['active']            ?? 1);
        return $row;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Day parsing
    // ──────────────────────────────────────────────────────────────────────────

    public function parseDays(string $days): array
    {
        $lower = strtolower(trim($days));

        if ($lower === 'weekdays') return [1, 2, 3, 4, 5];
        if ($lower === 'weekends') return [6, 7];
        if ($lower === 'all')      return [1, 2, 3, 4, 5, 6, 7];

        $result = [];
        foreach (explode(',', $days) as $part) {
            $short = strtolower(substr(trim($part), 0, 3));
            if (isset(self::DAY_MAP[$short])) {
                $result[] = self::DAY_MAP[$short];
            }
        }
        return array_unique($result);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Active route detection
    // ──────────────────────────────────────────────────────────────────────────

    public function getActiveRoutes(?int $dayOverride = null, ?string $timeOverride = null): array
    {
        date_default_timezone_set($this->getTimezone());
        $now     = time();
        $curDay  = $dayOverride  ?? (int)date('N', $now);
        $curTime = $timeOverride ?? date('H:i', $now);
        $before  = $this->getCollectionWindowBefore();
        $after   = $this->getCollectionWindowAfter();

        $active = [];

        foreach ($this->getAllActiveRoutes() as $route) {
            foreach ($route['schedule'] ?? [] as $sched) {
                $days = $this->parseDays($sched['days'] ?? '');
                if (!in_array($curDay, $days, true)) {
                    continue;
                }

                if (isset($sched['depart'])) {
                    $collectAt    = $sched['depart'];
                    $scheduleMode = 'depart';
                } elseif (isset($sched['arrive'])) {
                    $collectAt    = $this->estimateDepartureTime($sched['arrive']);
                    $scheduleMode = 'arrive';
                } else {
                    continue;
                }

                if ($this->isWithinWindow($curTime, $collectAt, $before, $after)) {
                    $active[] = array_merge($route, [
                        '_schedule'       => $sched,
                        '_schedule_mode'  => $scheduleMode,
                        '_scheduled_time' => $sched['arrive'] ?? $sched['depart'],
                        '_collect_at'     => $collectAt,
                    ]);
                    break;
                }
            }
        }

        return $active;
    }

    private function estimateDepartureTime(string $arriveTime): string
    {
        [$h, $m] = explode(':', $arriveTime);
        $ts = mktime((int)$h, (int)$m, 0);
        $ts -= 45 * 60;
        return date('H:i', $ts);
    }

    private function isWithinWindow(string $currentTime, string $collectAt, int $before, int $after): bool
    {
        $cur     = $this->timeToMinutes($currentTime);
        $collect = $this->timeToMinutes($collectAt);
        return $cur >= ($collect - $before) && $cur <= ($collect + $after);
    }

    private function timeToMinutes(string $time): int
    {
        [$h, $m] = explode(':', $time);
        return (int)$h * 60 + (int)$m;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Full schedule (for --schedule output and cron generation)
    // ──────────────────────────────────────────────────────────────────────────

    public function getFullSchedule(): array
    {
        $schedule = [];

        foreach ($this->getAllActiveRoutes() as $route) {
            foreach ($route['schedule'] ?? [] as $sched) {
                $days = $this->parseDays($sched['days'] ?? '');

                foreach ($days as $day) {
                    if (!isset($schedule[$day])) {
                        $schedule[$day] = [];
                    }

                    if (isset($sched['depart'])) {
                        $mode      = 'depart';
                        $time      = $sched['depart'];
                        $collectAt = $time;
                    } else {
                        $mode      = 'arrive';
                        $time      = $sched['arrive'];
                        $collectAt = $this->estimateDepartureTime($time);
                    }

                    $schedule[$day][] = [
                        'route_id'   => $route['id'],
                        'label'      => $route['label'],
                        'mode'       => $mode,
                        'time'       => $time,
                        'collect_at' => $collectAt,
                    ];
                }
            }
        }

        ksort($schedule);
        return $schedule;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Alert settings (thresholds — still from settings table)
    // ──────────────────────────────────────────────────────────────────────────

    public function getAlertSettings(): array
    {
        return [
            'traffic_threshold_percent' => (int)$this->getSetting('alert_traffic_threshold', '30'),
            'min_samples_for_alerts'    => (int)$this->getSetting('alert_min_samples', '5'),
            'max_alerts_per_day'        => (int)$this->getSetting('alert_max_per_day', '3'),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Channel profile lookups
    // ──────────────────────────────────────────────────────────────────────────

    public function getTelegramProfile(string $id): ?array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM telegram_profiles WHERE id = :id AND enabled = 1");
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $row['chat_ids'] = $this->parseCommaSeparated($row['chat_ids']);
            $row['enabled']  = (bool)(int)$row['enabled'];
            return $row;
        } catch (Exception $e) {
            return null;
        }
    }

    public function getEmailProfile(string $id): ?array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM email_profiles WHERE id = :id AND enabled = 1");
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $row['recipients']  = $this->parseCommaSeparated($row['recipients']);
            $row['smtp_port']   = (int)$row['smtp_port'];
            $row['enabled']     = (bool)(int)$row['enabled'];
            return $row;
        } catch (Exception $e) {
            return null;
        }
    }

    public function getSignalProfile(string $id): ?array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM signal_profiles WHERE id = :id AND enabled = 1");
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $row['recipient_numbers'] = $this->parseCommaSeparated($row['recipient_numbers']);
            $row['enabled']           = (bool)(int)$row['enabled'];
            return $row;
        } catch (Exception $e) {
            return null;
        }
    }

    public function getViberProfile(string $id): ?array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM viber_profiles WHERE id = :id AND enabled = 1");
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $row['receiver_ids'] = $this->parseCommaSeparated($row['receiver_ids']);
            $row['enabled']      = (bool)(int)$row['enabled'];
            return $row;
        } catch (Exception $e) {
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Alert profile lookups
    // ──────────────────────────────────────────────────────────────────────────

    /** Returns one alert profile (enabled only). Channels decoded to array. */
    public function getAlertProfile(string $id): ?array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM alert_profiles WHERE id = :id AND enabled = 1");
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $row['channels'] = json_decode($row['channels'] ?? '[]', true) ?: [];
            $row['enabled']  = (bool)(int)$row['enabled'];
            return $row;
        } catch (Exception $e) {
            return null;
        }
    }

    /** Returns all alert profiles (including disabled), ordered by label. */
    public function getAllAlertProfiles(): array
    {
        try {
            $rows = $this->pdo->query("SELECT * FROM alert_profiles ORDER BY label")
                              ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
        return array_map(function ($row) {
            $row['channels'] = json_decode($row['channels'] ?? '[]', true) ?: [];
            $row['enabled']  = (bool)(int)$row['enabled'];
            return $row;
        }, $rows);
    }

    /** Returns enabled alert profiles assigned to a route. */
    public function getRouteAlertProfiles(array $route): array
    {
        $profileIds = $route['alert_profile_ids'] ?? [];
        $profiles   = [];
        foreach ($profileIds as $pid) {
            $p = $this->getAlertProfile($pid);
            if ($p) {
                $profiles[] = $p;
            }
        }
        return $profiles;
    }

    /**
     * Returns all channel profiles grouped by type.
     * Includes disabled profiles (for settings UI). Passwords returned in full
     * (endpoint is auth-gated).
     */
    public function getAllChannelProfiles(): array
    {
        $result = [];
        foreach (['telegram', 'email', 'signal', 'viber'] as $type) {
            $table = $type . '_profiles';
            try {
                $rows = $this->pdo->query("SELECT * FROM {$table} ORDER BY label")
                                  ->fetchAll(PDO::FETCH_ASSOC);
                $result[$type] = array_map(function ($row) {
                    $row['enabled'] = (bool)(int)$row['enabled'];
                    return $row;
                }, $rows);
            } catch (Exception $e) {
                $result[$type] = [];
            }
        }
        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Channel profile CRUD
    // ──────────────────────────────────────────────────────────────────────────

    public function saveChannelProfile(string $type, array $data): void
    {
        $now = date('Y-m-d H:i:s');

        switch ($type) {
            case 'telegram':
                $st = $this->pdo->prepare("
                    INSERT INTO telegram_profiles (id, label, bot_token, chat_ids, enabled, created_at, updated_at)
                    VALUES (:id, :label, :bot_token, :chat_ids, :enabled, :created_at, :updated_at)
                    ON CONFLICT(id) DO UPDATE SET
                        label = excluded.label, bot_token = excluded.bot_token,
                        chat_ids = excluded.chat_ids, enabled = excluded.enabled,
                        updated_at = excluded.updated_at
                ");
                $st->execute([
                    ':id'         => $data['id'],
                    ':label'      => $data['label'],
                    ':bot_token'  => $data['bot_token']  ?? '',
                    ':chat_ids'   => $data['chat_ids']   ?? '',
                    ':enabled'    => (int)(bool)($data['enabled'] ?? 1),
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                break;

            case 'email':
                $st = $this->pdo->prepare("
                    INSERT INTO email_profiles
                        (id, label, smtp_host, smtp_port, smtp_encryption, smtp_user, smtp_pass,
                         from_address, from_name, recipients, enabled, created_at, updated_at)
                    VALUES
                        (:id, :label, :smtp_host, :smtp_port, :smtp_encryption, :smtp_user, :smtp_pass,
                         :from_address, :from_name, :recipients, :enabled, :created_at, :updated_at)
                    ON CONFLICT(id) DO UPDATE SET
                        label = excluded.label, smtp_host = excluded.smtp_host,
                        smtp_port = excluded.smtp_port, smtp_encryption = excluded.smtp_encryption,
                        smtp_user = excluded.smtp_user, smtp_pass = excluded.smtp_pass,
                        from_address = excluded.from_address, from_name = excluded.from_name,
                        recipients = excluded.recipients, enabled = excluded.enabled,
                        updated_at = excluded.updated_at
                ");
                $st->execute([
                    ':id'              => $data['id'],
                    ':label'           => $data['label'],
                    ':smtp_host'       => $data['smtp_host']       ?? '',
                    ':smtp_port'       => (int)($data['smtp_port'] ?? 587),
                    ':smtp_encryption' => $data['smtp_encryption']  ?? 'tls',
                    ':smtp_user'       => $data['smtp_user']        ?? '',
                    ':smtp_pass'       => $data['smtp_pass']        ?? '',
                    ':from_address'    => $data['from_address']     ?? '',
                    ':from_name'       => $data['from_name']        ?? 'Route Tracker',
                    ':recipients'      => $data['recipients']       ?? '',
                    ':enabled'         => (int)(bool)($data['enabled'] ?? 1),
                    ':created_at'      => $now,
                    ':updated_at'      => $now,
                ]);
                break;

            case 'signal':
                $st = $this->pdo->prepare("
                    INSERT INTO signal_profiles
                        (id, label, api_url, sender_number, recipient_numbers, enabled, created_at, updated_at)
                    VALUES
                        (:id, :label, :api_url, :sender_number, :recipient_numbers, :enabled, :created_at, :updated_at)
                    ON CONFLICT(id) DO UPDATE SET
                        label = excluded.label, api_url = excluded.api_url,
                        sender_number = excluded.sender_number,
                        recipient_numbers = excluded.recipient_numbers,
                        enabled = excluded.enabled, updated_at = excluded.updated_at
                ");
                $st->execute([
                    ':id'               => $data['id'],
                    ':label'            => $data['label'],
                    ':api_url'          => $data['api_url']           ?? '',
                    ':sender_number'    => $data['sender_number']     ?? '',
                    ':recipient_numbers'=> $data['recipient_numbers'] ?? '',
                    ':enabled'          => (int)(bool)($data['enabled'] ?? 1),
                    ':created_at'       => $now,
                    ':updated_at'       => $now,
                ]);
                break;

            case 'viber':
                $st = $this->pdo->prepare("
                    INSERT INTO viber_profiles
                        (id, label, auth_token, receiver_ids, enabled, created_at, updated_at)
                    VALUES
                        (:id, :label, :auth_token, :receiver_ids, :enabled, :created_at, :updated_at)
                    ON CONFLICT(id) DO UPDATE SET
                        label = excluded.label, auth_token = excluded.auth_token,
                        receiver_ids = excluded.receiver_ids, enabled = excluded.enabled,
                        updated_at = excluded.updated_at
                ");
                $st->execute([
                    ':id'          => $data['id'],
                    ':label'       => $data['label'],
                    ':auth_token'  => $data['auth_token']  ?? '',
                    ':receiver_ids'=> $data['receiver_ids'] ?? '',
                    ':enabled'     => (int)(bool)($data['enabled'] ?? 1),
                    ':created_at'  => $now,
                    ':updated_at'  => $now,
                ]);
                break;

            default:
                throw new InvalidArgumentException("Unknown channel type: {$type}");
        }
    }

    public function deleteChannelProfile(string $type, string $id): void
    {
        $allowed = ['telegram', 'email', 'signal', 'viber'];
        if (!in_array($type, $allowed, true)) {
            throw new InvalidArgumentException("Unknown channel type: {$type}");
        }
        $table = $type . '_profiles';
        $st = $this->pdo->prepare("DELETE FROM {$table} WHERE id = :id");
        $st->execute([':id' => $id]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Alert profile CRUD
    // ──────────────────────────────────────────────────────────────────────────

    public function saveAlertProfile(array $data): void
    {
        $now      = date('Y-m-d H:i:s');
        $channels = is_array($data['channels'] ?? null)
            ? json_encode($data['channels'], JSON_UNESCAPED_UNICODE)
            : ($data['channels'] ?? '[]');

        $st = $this->pdo->prepare("
            INSERT INTO alert_profiles (id, label, channels, enabled, created_at, updated_at)
            VALUES (:id, :label, :channels, :enabled, :created_at, :updated_at)
            ON CONFLICT(id) DO UPDATE SET
                label = excluded.label, channels = excluded.channels,
                enabled = excluded.enabled, updated_at = excluded.updated_at
        ");
        $st->execute([
            ':id'         => $data['id'],
            ':label'      => $data['label'],
            ':channels'   => $channels,
            ':enabled'    => (int)(bool)($data['enabled'] ?? 1),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function deleteAlertProfile(string $id): void
    {
        $st = $this->pdo->prepare("DELETE FROM alert_profiles WHERE id = :id");
        $st->execute([':id' => $id]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Monitoring tokens
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Returns an existing valid token for the route/schedule/date, or creates a new one.
     * Token expires 30 minutes after the target arrival time.
     */
    public function createOrGetMonitoringToken(
        string $routeId, string $scheduleKey, string $date,
        string $arriveTime, string $routeLabel
    ): string {
        $st = $this->pdo->prepare("
            SELECT token FROM monitoring_tokens
            WHERE route_id=? AND schedule_key=? AND date=? AND expires_at > datetime('now')
        ");
        $st->execute([$routeId, $scheduleKey, $date]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row['token'];
        }

        $expires = date('Y-m-d H:i:s', strtotime("{$date} {$arriveTime} +30 minutes"));
        $token   = bin2hex(random_bytes(16));
        $now     = date('Y-m-d H:i:s');

        $this->pdo->prepare("
            INSERT OR REPLACE INTO monitoring_tokens
                (token, route_id, schedule_key, date, arrive_time, route_label, expires_at, created_at)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([$token, $routeId, $scheduleKey, $date, $arriveTime, $routeLabel, $expires, $now]);

        return $token;
    }

    /**
     * Returns the monitor URL for an existing valid token for this window, or '' if none.
     * Does NOT create a new token — tokens are only created when an alert fires.
     */
    public function getMonitoringUrl(string $routeId, string $scheduleKey, string $date): string
    {
        $appUrl = rtrim($this->getSetting('app_url', ''), '/');
        if (!$appUrl) return '';

        $st = $this->pdo->prepare("
            SELECT token FROM monitoring_tokens
            WHERE route_id=? AND schedule_key=? AND date=? AND expires_at > datetime('now')
        ");
        $st->execute([$routeId, $scheduleKey, $date]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return '';

        return $appUrl . '/monitor.php?token=' . $row['token'];
    }

    /** Returns token row or null if not found (may be expired). */
    public function getMonitoringToken(string $token): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM monitoring_tokens WHERE token=?");
        $st->execute([$token]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Deletes all tokens whose expires_at is in the past. Returns count deleted. */
    public function cleanExpiredMonitoringTokens(): int
    {
        $st = $this->pdo->prepare("DELETE FROM monitoring_tokens WHERE expires_at < datetime('now')");
        $st->execute();
        return $st->rowCount();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function parseCommaSeparated(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
