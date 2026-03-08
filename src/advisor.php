#!/usr/bin/env php
<?php

/**
 * advisor.php — Route Tracker v3
 * Combined cron entry point: runs advisor checks + data collection every 5 min.
 *
 * Suggested cron line:
 *   *\/5 * * * * php /var/www/html/apps/tracker/advisor.php >> data/advisor.log 2>&1
 */

$baseDir = dirname(__DIR__);
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/AlertManager.php';
require_once __DIR__ . '/collector.php';      // provides processRoute(), insertTrip(), etc.
require_once __DIR__ . '/DepartureAdvisor.php';

// ─── Boot ─────────────────────────────────────────────────────────────────────

try {
    $config = Config::load($baseDir);
    date_default_timezone_set($config->getTimezone());
} catch (Exception $e) {
    die("[" . date('Y-m-d H:i:s') . "] Config error: " . $e->getMessage() . "\n");
}

$dataDir = $baseDir . '/' . Config::deploy('data_dir');
@mkdir($dataDir, Config::deploy('dir_permissions'), true);

$logFile  = $dataDir . '/advisor.log';
$collLog  = $dataDir . '/collector.log';
$pdo      = $config->getPdo();
$alertMgr = new AlertManager($config);
$advisor  = new DepartureAdvisor($config, $alertMgr);

advisorLog($logFile, "Run starting");

// ─── Get all active routes ────────────────────────────────────────────────────

$routes = $config->getAllActiveRoutes();

if (empty($routes)) {
    advisorLog($logFile, "No active routes.");
    exit(0);
}

// ─── Determine routes in collection window (only query once) ─────────────────

$routesInWindow = [];
foreach ($config->getActiveRoutes() as $r) {
    $routesInWindow[$r['id']] = $r;
}

// ─── Process each active route ────────────────────────────────────────────────

foreach ($routes as $route) {
    // 1. Advisor checks (arrive-mode schedules only)
    if (!empty($route['advisor_enabled'])) {
        foreach ($route['schedule'] ?? [] as $sched) {
            if (!isset($sched['arrive'])) {
                continue;
            }
            try {
                $advisor->run($route, $sched);
            } catch (Exception $e) {
                advisorLog($logFile, "Advisor error [{$route['id']}]: " . $e->getMessage());
            }
        }
    }

    // 2. Data collection if route is in its scheduled window
    if (isset($routesInWindow[$route['id']])) {
        try {
            processRoute($routesInWindow[$route['id']], $pdo, $config, $alertMgr, $collLog, false);
        } catch (Exception $e) {
            advisorLog($logFile, "Collection error [{$route['id']}]: " . $e->getMessage());
        }
    }
}

// ─── Housekeeping ─────────────────────────────────────────────────────────────

$deleted = $config->cleanExpiredMonitoringTokens();
if ($deleted > 0) {
    advisorLog($logFile, "Housekeeping: removed {$deleted} expired monitoring token(s)");
}

// ─── Deactivate expired one-time routes ───────────────────────────────────────

$windowAfterMin = $config->getCollectionWindowAfter();
$nowDt          = new DateTime();

try {
    $expiring = $pdo->query(
        "SELECT id, schedule FROM routes WHERE one_time = 1 AND active = 1 AND one_time_used = 0"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $expiring = [];
}

foreach ($expiring as $rt) {
    $schedules = json_decode($rt['schedule'] ?? '[]', true) ?: [];
    foreach ($schedules as $sched) {
        if (!isset($sched['arrive'])) {
            continue;
        }
        $parts = explode(':', $sched['arrive']);
        if (count($parts) < 2) {
            continue;
        }
        $cutoff = new DateTime();
        $cutoff->setTime((int)$parts[0], (int)$parts[1], 0);
        $cutoff->modify("+{$windowAfterMin} minutes");

        if ($nowDt >= $cutoff) {
            $pdo->prepare("UPDATE routes SET active=0, one_time_used=1, updated_at=? WHERE id=?")
                ->execute([date('Y-m-d H:i:s'), $rt['id']]);
            advisorLog($logFile, "One-time route '{$rt['id']}' deactivated (window expired)");
            break;
        }
    }
}

advisorLog($logFile, "Run complete");
exit(0);

// ─── Logging ─────────────────────────────────────────────────────────────────

function advisorLog(string $logFile, string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}
