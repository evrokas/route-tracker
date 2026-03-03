<?php

/**
 * Config.php — Route Tracker v3
 * SQLite-backed configuration loader. Replaces YAML-based v2 loader.
 *
 * All settings come from the `settings` table; routes from the `routes` table.
 * Public method signatures are preserved for backward compatibility.
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
        // In v3 the dotKey IS the settings table key (no nesting needed)
        // Legacy dot-keys like 'google_maps.api_key' → 'google_maps_api_key'
        $flat = str_replace('.', '_', $dotKey);

        if (array_key_exists($flat, $this->settings)) {
            return $this->settings[$flat];
        }

        // Also try the original key as-is
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
    // Convenience getters (preserve v2 interface)
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
    // Route getters (query routes table)
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

    /** Decode JSON fields and normalise a route DB row to the v2-compatible array format. */
    private function decodeRoute(array $row): array
    {
        $row['schedule']        = json_decode($row['schedule']        ?? '[]', true) ?: [];
        $row['advisor_stages']  = json_decode($row['advisor_stages']  ?? '[]', true) ?: [];
        $row['alert_channels']  = json_decode($row['alert_channels']  ?? '[]', true) ?: [];
        $row['advisor_enabled'] = (bool)(int)($row['advisor_enabled'] ?? 0);
        $row['active']          = (bool)(int)($row['active']          ?? 1);
        return $row;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Day parsing
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Parse a day string into ISO day numbers (1=Mon .. 7=Sun).
     *
     * Accepts:
     *   "Mon"             → [1]
     *   "Mon,Wed,Fri"     → [1,3,5]
     *   "Weekdays"        → [1,2,3,4,5]
     *   "Weekends"        → [6,7]
     *   "All"             → [1,2,3,4,5,6,7]
     */
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

    /**
     * Return active routes within their collection window right now.
     *
     * @param int|null    $dayOverride  ISO day (1–7). Null = current day.
     * @param string|null $timeOverride "HH:MM". Null = current time.
     */
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
                $days    = $this->parseDays($sched['days'] ?? '');
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
                    break; // only add route once even if multiple entries match
                }
            }
        }

        return $active;
    }

    private function estimateDepartureTime(string $arriveTime): string
    {
        [$h, $m] = explode(':', $arriveTime);
        $ts = mktime((int)$h, (int)$m, 0);
        $ts -= 45 * 60; // 45 min earlier
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
    // Alert config getters (preserve v2 interface)
    // ──────────────────────────────────────────────────────────────────────────

    public function getAlertSettings(): array
    {
        return [
            'traffic_threshold_percent' => (int)$this->getSetting('alert_traffic_threshold', '30'),
            'min_samples_for_alerts'    => (int)$this->getSetting('alert_min_samples', '5'),
            'max_alerts_per_day'        => (int)$this->getSetting('alert_max_per_day', '3'),
        ];
    }

    public function getAlertConfig(string $channel): array
    {
        switch ($channel) {
            case 'telegram':
                return [
                    'enabled'   => (bool)(int)$this->getSetting('telegram_enabled'),
                    'bot_token' => $this->getSetting('telegram_bot_token'),
                    'chat_ids'  => $this->parseCommaSeparated($this->getSetting('telegram_chat_ids')),
                ];
            case 'email':
                return [
                    'enabled'           => (bool)(int)$this->getSetting('email_enabled'),
                    'method'            => 'smtp',
                    'smtp_host'         => $this->getSetting('email_host'),
                    'smtp_port'         => (int)$this->getSetting('email_port', '587'),
                    'smtp_username'     => $this->getSetting('email_user'),
                    'smtp_password'     => $this->getSetting('email_pass'),
                    'from_address'      => $this->getSetting('email_from'),
                    'from_name'         => 'Route Tracker',
                    'recipients'        => $this->parseCommaSeparated($this->getSetting('email_to')),
                ];
            case 'viber':
                return [
                    'enabled'      => (bool)(int)$this->getSetting('viber_enabled'),
                    'auth_token'   => $this->getSetting('viber_auth_token'),
                    'receiver_ids' => $this->parseCommaSeparated($this->getSetting('viber_receiver_ids')),
                ];
            case 'signal':
                return [
                    'enabled'           => (bool)(int)$this->getSetting('signal_enabled'),
                    'api_url'           => $this->getSetting('signal_api_url'),
                    'sender_number'     => $this->getSetting('signal_sender'),
                    'recipient_numbers' => $this->parseCommaSeparated($this->getSetting('signal_recipients')),
                ];
            default:
                return [];
        }
    }

    public function isAlertEnabled(string $channel): bool
    {
        return (bool)(int)$this->getSetting($channel . '_enabled');
    }

    public function getRouteAlertChannels(array $route): array
    {
        $routeChannels = $route['alert_channels'] ?? [];
        return array_values(array_filter($routeChannels, fn($ch) => $this->isAlertEnabled($ch)));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function parseCommaSeparated(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
