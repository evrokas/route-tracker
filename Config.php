<?php

/**
 * Config.php — Route Tracker v2
 * YAML configuration loader + schedule logic
 */

class Config
{
    private static ?Config $instance = null;
    private string $baseDir;
    private array $config  = [];
    private array $routes  = [];
    private array $alerts  = [];

    // Day name → ISO day number (1=Mon .. 7=Sun)
    private const DAY_MAP = [
        'mon' => 1, 'tue' => 2, 'wed' => 3,
        'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
    ];

    private function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/');
        $this->loadYaml();
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

    // ──────────────────────────────────────────────────────────────────────────
    // Internal loading
    // ──────────────────────────────────────────────────────────────────────────

    private function loadYaml(): void
    {
        if (!function_exists('yaml_parse_file')) {
            throw new RuntimeException(
                "PHP yaml extension not available.\n" .
                "Install: sudo apt install php-dev php-pear libyaml-dev && sudo pecl install yaml"
            );
        }

        $this->config = $this->parseYaml('config.yaml');
        $this->routes = $this->parseYaml('routes.yaml');
        $this->alerts = $this->parseYaml('alerts.yaml');
    }

    private function parseYaml(string $filename): array
    {
        $path = "{$this->baseDir}/{$filename}";
        if (!file_exists($path)) {
            throw new RuntimeException("Configuration file not found: {$path}");
        }
        $data = yaml_parse_file($path);
        if ($data === false) {
            throw new RuntimeException("Failed to parse YAML: {$path}");
        }
        return is_array($data) ? $data : [];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Generic dot-notation getter
    // ──────────────────────────────────────────────────────────────────────────

    public function get(string $dotKey, $default = null)
    {
        $keys = explode('.', $dotKey);

        // Try all three config arrays
        foreach ([$this->config, $this->routes, $this->alerts] as $src) {
            $val = $this->dotGet($src, $keys);
            if ($val !== null) {
                return $val;
            }
        }
        return $default;
    }

    private function dotGet(array $arr, array $keys)
    {
        $cur = $arr;
        foreach ($keys as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) {
                return null;
            }
            $cur = $cur[$k];
        }
        return $cur;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Convenience getters
    // ──────────────────────────────────────────────────────────────────────────

    public function getApiKey(): string
    {
        return (string)($this->config['google_maps']['api_key'] ?? '');
    }

    public function getApiToken(): string
    {
        return (string)($this->config['api_token'] ?? '');
    }
    
    public function getDashboardPassword(): string  { return (string)($this->config['dashboard_password'] ?? ''); }

    public function getDbPath(): string
    {
        $rel = $this->config['database']['path'] ?? 'data/routes.sqlite';
        // If already absolute, return as-is
        if ($rel[0] === '/') {
            return $rel;
        }
        return $this->baseDir . '/' . $rel;
    }

    public function getTimezone(): string
    {
        return (string)($this->config['timezone'] ?? 'Europe/Athens');
    }

    public function getCollectionWindowBefore(): int
    {
        return (int)($this->config['collection']['window_before_minutes'] ?? 15);
    }

    public function getCollectionWindowAfter(): int
    {
        return (int)($this->config['collection']['window_after_minutes'] ?? 5);
    }

    public function requestAlternatives(): bool
    {
        return (bool)($this->config['collection']['request_alternatives'] ?? true);
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
        return $this->routes['routes'] ?? [];
    }

    public function getRoute(string $id): ?array
    {
        foreach ($this->getAllRoutes() as $route) {
            if (($route['id'] ?? '') === $id) {
                return $route;
            }
        }
        return null;
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

        if ($lower === 'weekdays') {
            return [1, 2, 3, 4, 5];
        }
        if ($lower === 'weekends') {
            return [6, 7];
        }
        if ($lower === 'all') {
            return [1, 2, 3, 4, 5, 6, 7];
        }

        $result = [];
        foreach (explode(',', $days) as $part) {
            $part  = strtolower(trim($part));
            $short = substr($part, 0, 3);
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
     * Return routes that are within their collection window right now.
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

        foreach ($this->getAllRoutes() as $route) {
            foreach ($route['schedule'] ?? [] as $sched) {
                $days      = $this->parseDays($sched['days'] ?? '');
                $isToday   = in_array($curDay, $days, true);
                if (!$isToday) {
                    continue;
                }

                // Resolve the collect-at time
                if (isset($sched['depart'])) {
                    $collectAt   = $sched['depart'];
                    $scheduleMode = 'depart';
                } elseif (isset($sched['arrive'])) {
                    // Estimate departure time = arrive − 30min − buffer
                    $collectAt   = $this->estimateDepartureTime($sched['arrive'], $route['id']);
                    $scheduleMode = 'arrive';
                } else {
                    continue;
                }

                // Check if current time is within the window
                if ($this->isWithinWindow($curTime, $collectAt, $before, $after)) {
                    $active[] = array_merge($route, [
                        '_schedule'       => $sched,
                        '_schedule_mode'  => $scheduleMode,
                        '_scheduled_time' => $sched['arrive'] ?? $sched['depart'],
                        '_collect_at'     => $collectAt,
                    ]);
                    break; // Only add route once even if multiple schedule entries match
                }
            }
        }

        return $active;
    }

    /**
     * Estimate when to start collecting for an arrive-mode route.
     * In future this can query DB for historical average.
     */
    private function estimateDepartureTime(string $arriveTime, string $routeId): string
    {
        // Default estimate: 30 min travel + 15 min buffer
        [$h, $m] = explode(':', $arriveTime);
        $ts = mktime((int)$h, (int)$m, 0);
        $ts -= (30 + 15) * 60; // 45 min earlier
        return date('H:i', $ts);
    }

    /**
     * Is $currentTime within [$collectAt − $before, $collectAt + $after]?
     */
    private function isWithinWindow(string $currentTime, string $collectAt, int $before, int $after): bool
    {
        $cur      = $this->timeToMinutes($currentTime);
        $collect  = $this->timeToMinutes($collectAt);
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

        foreach ($this->getAllRoutes() as $route) {
            foreach ($route['schedule'] ?? [] as $sched) {
                $days = $this->parseDays($sched['days'] ?? '');

                foreach ($days as $day) {
                    if (!isset($schedule[$day])) {
                        $schedule[$day] = [];
                    }

                    if (isset($sched['depart'])) {
                        $mode       = 'depart';
                        $time       = $sched['depart'];
                        $collectAt  = $time;
                    } else {
                        $mode       = 'arrive';
                        $time       = $sched['arrive'];
                        $collectAt  = $this->estimateDepartureTime($time, $route['id']);
                    }

                    $schedule[$day][] = [
                        'route_id'    => $route['id'],
                        'label'       => $route['label'],
                        'mode'        => $mode,
                        'time'        => $time,
                        'collect_at'  => $collectAt,
                    ];
                }
            }
        }

        ksort($schedule);
        return $schedule;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Alert config getters
    // ──────────────────────────────────────────────────────────────────────────

    public function getAlertSettings(): array
    {
        return $this->alerts['alert_settings'] ?? [
            'traffic_threshold_percent' => 30,
            'min_samples_for_alerts'    => 5,
            'max_alerts_per_day'        => 3,
        ];
    }

    public function getAlertConfig(string $channel): array
    {
        return $this->alerts[$channel] ?? [];
    }

    public function isAlertEnabled(string $channel): bool
    {
        return (bool)($this->alerts[$channel]['enabled'] ?? false);
    }

    public function getRouteAlertChannels(array $route): array
    {
        $routeChannels = $route['alerts'] ?? [];
        return array_values(array_filter($routeChannels, fn($ch) => $this->isAlertEnabled($ch)));
    }
}
