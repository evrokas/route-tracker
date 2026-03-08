<?php
/**
 * dashboard.php — Route Tracker v3
 * Session-authenticated dashboard.
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/src/Config.php';
require_once $baseDir . '/src/auth.php';

Auth::requireLogin();

try {
    $config = Config::load($baseDir);
} catch (Exception $e) {
    die('<pre>Config error: ' . htmlspecialchars($e->getMessage()) . "\n\nRun: php schema.php --init</pre>");
}

if (isset($_GET['logout'])) {
    Auth::logout($config);
    header('Location: login.php');
    exit;
}

$apiBase = 'api.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🗺️ Route Tracker</title>
<link rel="stylesheet" href="css/dashboard.css">
</head>
<body>

<!-- ─── HEADER ──────────────────────────────────────────────────────────── -->

<div class="header">
  <h1>🗺️ Route <span>Tracker</span></h1>
  <div class="header-controls">
    <select id="selYear"><option value="">All Years</option></select>
    <select id="selMonth">
      <option value="">All Months</option>
      <option value="1">January</option><option value="2">February</option>
      <option value="3">March</option><option value="4">April</option>
      <option value="5">May</option><option value="6">June</option>
      <option value="7">July</option><option value="8">August</option>
      <option value="9">September</option><option value="10">October</option>
      <option value="11">November</option><option value="12">December</option>
    </select>
    <button class="primary" onclick="refresh()">↺ Refresh</button>
    <span id="filterBadge" style="display:none;background:#5b7cf6;color:#fff;font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px;"></span>
    <a href="settings.php" style="font-size:12px;color:var(--muted);text-decoration:none;padding:6px 10px;">⚙️ Settings</a>
    <a href="?logout=1" style="font-size:12px;color:var(--muted);text-decoration:none;padding:6px 10px;">Sign out</a>
    <span id="statusWrap">
      <span class="status-dot" id="statusDot"></span>
      <span id="statusText">–</span>
    </span>
  </div>
</div>

<!-- ─── FILTER BAR ─────────────────────────────────────────────────────── -->

<div class="route-bar" id="filterBar">
  <div id="routeBar" class="filter-group">
    <div class="chip active" data-id="">All Routes</div>
  </div>
  <div class="filter-sep" id="timeSep" style="display:none"></div>
  <div id="timeBar" class="filter-group" style="display:none">
    <div class="chip time-chip active" data-tw="all">All</div>
    <div class="chip time-chip" data-tw="today">Today</div>
    <div class="chip time-chip" data-tw="upcoming">Upcoming</div>
    <div class="chip time-chip" data-tw="next3h">Next 3 h</div>
    <div class="chip time-chip" data-tw="next">Next</div>
  </div>
</div>

<!-- ─── TAB BAR ────────────────────────────────────────────────────────── -->

<div class="tab-bar">
  <button class="tab active" data-tab="advisor">Advisor</button>
  <button class="tab" data-tab="overview">Overview</button>
  <button class="tab" data-tab="best">Best Routes</button>
  <button class="tab" data-tab="byday">By Day</button>
  <button class="tab" data-tab="trends">Trends</button>
  <button class="tab" data-tab="history">History</button>
</div>

<!-- ─── CONTENT ────────────────────────────────────────────────────────── -->

<div class="content" id="content">
  <div class="loading"><div class="spinner"></div>Loading…</div>
</div>

<script>
  const API_BASE  = <?= json_encode($apiBase) ?>;
  const API_TOKEN = '';
</script>

<script src="js/dashboard.js"></script>

</body>
</html>
