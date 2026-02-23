<?php
/**
 * dashboard.php — Route Tracker v2
 * Session-authenticated dashboard. No credentials are sent to the browser.
 */

$baseDir = __DIR__;
require_once $baseDir . '/Config.php';
require_once $baseDir . '/auth.php';

// ─── Require login ────────────────────────────────────────────────────────────
Auth::requireLogin();

// ─── Handle logout ────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    Auth::logout();
    header('Location: login.php');
    exit;
}

// ─── Load config ─────────────────────────────────────────────────────────────
$configError = null;
try {
    $config = Config::load($baseDir);
} catch (Exception $e) {
    $configError = $e->getMessage();
}

// API_BASE is just a relative path — safe to expose
// API_TOKEN is NOT injected; api.php validates via session instead
$apiBase = 'api.php';
?>

<!DOCTYPE html>

<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🗺️ Route Tracker</title>
<link rel="stylesheet" href="dashboard.css">
</head>
<body>

<?php if ($configError): ?>

<div style="background:#7f1d1d;color:#fecaca;padding:16px 24px;font-family:monospace;font-size:13px;border-bottom:1px solid #991b1b;">
  <strong>⚠ Configuration Error:</strong> <?= htmlspecialchars($configError) ?><br>
  Check that <code>config.yaml</code>, <code>routes.yaml</code>, and <code>alerts.yaml</code> exist and are valid YAML.
</div>
<?php endif; ?>

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
    <a href="?logout=1" style="font-size:12px;color:var(--muted);text-decoration:none;padding:6px 10px;">Sign out</a>
    <span id="statusWrap">
      <span class="status-dot" id="statusDot"></span>
      <span id="statusText">–</span>
    </span>
  </div>
</div>

<!-- ─── ROUTE CHIPS ─────────────────────────────────────────────────────── -->

<div class="route-bar" id="routeBar">
  <div class="chip active" data-id="">All Routes</div>
</div>

<!-- ─── TAB BAR ────────────────────────────────────────────────────────── -->

<div class="tab-bar">
  <button class="tab active" data-tab="overview">Overview</button>
  <button class="tab" data-tab="best">Best Routes</button>
  <button class="tab" data-tab="byday">By Day</button>
  <button class="tab" data-tab="trends">Trends</button>
  <button class="tab" data-tab="history">History</button>
</div>

<!-- ─── CONTENT ────────────────────────────────────────────────────────── -->

<div class="content" id="content">
  <div class="loading"><div class="spinner"></div>Loading…</div>
</div>

<!-- ─── Only the base URL is injected — no token, no secrets ────────────── -->

<script>
  const API_BASE  = <?= json_encode($apiBase) ?>;
  const API_TOKEN = '';   // Not used — auth is handled via PHP session cookie
</script>

<script src="dashboard.js"></script>

</body>
</html>

