<?php
/**
 * monitor.php — Route Tracker v3
 * Public monitoring page: shows a live countdown for an advisor window.
 * No authentication required — access is controlled by the time-limited token.
 *
 * HTML:  monitor.php?token=<token>
 * JSON:  monitor.php?token=<token>&json=1   (returns current advisor state)
 */

$baseDir = __DIR__;
require_once $baseDir . '/Config.php';

$token  = trim($_GET['token'] ?? '');
$isJson = !empty($_GET['json']);

// ─── JSON helper ──────────────────────────────────────────────────────────────

function monErr(string $msg, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg]);
    exit;
}

// ─── Validate token ───────────────────────────────────────────────────────────

if (!$token) {
    if ($isJson) monErr('Missing token');
    http_response_code(400);
    renderErrorPage('Invalid Link', 'No monitoring token provided.');
}

try {
    $config = Config::load($baseDir);
    date_default_timezone_set($config->getTimezone());
} catch (Exception $e) {
    if ($isJson) monErr('Configuration error', 500);
    http_response_code(500);
    renderErrorPage('Server Error', 'Could not load configuration.');
}

$tokenData = $config->getMonitoringToken($token);

if (!$tokenData) {
    if ($isJson) monErr('Token not found or expired', 404);
    http_response_code(404);
    renderErrorPage('Link Not Found', 'This monitoring link is invalid or has already expired.');
}

$isExpired = strtotime($tokenData['expires_at']) < time();

// ─── Load current advisor state ───────────────────────────────────────────────

$pdo = $config->getPdo();
$st  = $pdo->prepare("
    SELECT * FROM advisor_state
    WHERE route_id=? AND schedule_key=? AND date=?
");
$st->execute([$tokenData['route_id'], $tokenData['schedule_key'], $tokenData['date']]);
$state = $st->fetch(PDO::FETCH_ASSOC) ?: [];

// ─── JSON endpoint ────────────────────────────────────────────────────────────

if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    $liveSec = isset($state['live_duration_seconds']) ? (int)$state['live_duration_seconds'] : null;
    echo json_encode([
        'recommended_departure' => $state['recommended_departure'] ?? null,
        'live_duration_seconds' => $liveSec,
        'live_duration_min'     => $liveSec !== null ? (int)ceil($liveSec / 60) : null,
        'stages_fired'          => json_decode($state['stages_fired'] ?? '[]', true) ?: [],
        'last_check'            => $state['last_check'] ?? null,
        'is_expired'            => $isExpired,
    ]);
    exit;
}

// ─── HTML page ────────────────────────────────────────────────────────────────

$routeLabel = $tokenData['route_label'];
$arriveTime = $tokenData['arrive_time'];   // "HH:MM"
$date       = $tokenData['date'];          // "YYYY-MM-DD"
$recDep     = $state['recommended_departure'] ?? null;
$liveSec    = isset($state['live_duration_seconds']) ? (int)$state['live_duration_seconds'] : null;
$liveMin    = $liveSec !== null ? (int)ceil($liveSec / 60) : null;
$lastCheck  = $state['last_check'] ?? null;
$stagesFired = json_decode($state['stages_fired'] ?? '[]', true) ?: [];
$expiresAt  = $tokenData['expires_at'];

// Build gmaps URL from route
$routeRow = $pdo->prepare("SELECT origin, destination, travel_mode FROM routes WHERE id=?");
$routeRow->execute([$tokenData['route_id']]);
$route = $routeRow->fetch(PDO::FETCH_ASSOC);
$gmapsUrl = '';
if ($route) {
    $gmapsUrl = "https://www.google.com/maps/dir/?api=1"
              . "&origin="      . urlencode($route['origin'])
              . "&destination=" . urlencode($route['destination'])
              . "&travelmode="  . urlencode($route['travel_mode'] ?? 'driving');
}

// Date display
$dateDisplay = (new DateTime($date))->format('l, j F Y');

// All stages for display
$allStages = ['planning', 'window', 'reminder', 'urgent', 'last_call'];
$stageLabels = [
    'planning'  => 'Planning',
    'window'    => 'Window',
    'reminder'  => 'Reminder',
    'urgent'    => 'Urgent',
    'last_call' => 'Last Call',
];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($routeLabel) ?> — Monitor</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:      #0f1117;
  --surface: #1a1d27;
  --border:  rgba(255,255,255,.10);
  --text:    #e8eaf0;
  --muted:   #7b82a0;
  --accent:  #5b7cf6;
  --green:   #4caf82;
  --yellow:  #f0b429;
  --red:     #e05252;
}

body {
  background: var(--bg);
  color: var(--text);
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 24px 16px 40px;
}

.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  width: 100%;
  max-width: 420px;
  padding: 24px;
  margin-bottom: 12px;
}

.route-label {
  font-size: 18px;
  font-weight: 700;
  letter-spacing: -.01em;
  margin-bottom: 4px;
}

.route-date {
  font-size: 13px;
  color: var(--muted);
}

.divider {
  border: none;
  border-top: 1px solid var(--border);
  margin: 18px 0;
}

/* Arrival countdown */
.arrive-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--muted);
  margin-bottom: 6px;
}

.arrive-time-target {
  font-size: 15px;
  color: var(--muted);
  margin-bottom: 12px;
}

.countdown {
  font-size: 52px;
  font-weight: 800;
  letter-spacing: -.03em;
  font-variant-numeric: tabular-nums;
  line-height: 1;
  margin-bottom: 6px;
  transition: color .3s;
}
.countdown.urgent  { color: var(--yellow); }
.countdown.overdue { color: var(--red); }

.countdown-sub {
  font-size: 13px;
  color: var(--muted);
  margin-bottom: 0;
}

/* Departure row */
.dep-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}

.dep-block { flex: 1; }

.dep-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--muted);
  margin-bottom: 4px;
}

.dep-value {
  font-size: 28px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  letter-spacing: -.02em;
}

.dep-countdown {
  font-size: 13px;
  color: var(--muted);
  margin-top: 3px;
  font-variant-numeric: tabular-nums;
}
.dep-countdown.urgent  { color: var(--yellow); }
.dep-countdown.overdue { color: var(--red); }

/* Live estimate */
.live-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  color: var(--muted);
}

.live-min {
  font-size: 20px;
  font-weight: 700;
  color: var(--text);
}

/* Stages */
.stages {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.stage-chip {
  font-size: 11px;
  padding: 3px 10px;
  border-radius: 12px;
  border: 1px solid var(--border);
  color: var(--muted);
}
.stage-chip.fired {
  background: rgba(76,175,130,.15);
  border-color: rgba(76,175,130,.3);
  color: var(--green);
}

/* Footer */
.last-update {
  font-size: 12px;
  color: var(--muted);
  text-align: center;
  margin-top: 4px;
}

.nav-btn {
  display: block;
  width: 100%;
  max-width: 420px;
  padding: 14px;
  background: var(--accent);
  color: #fff;
  border: none;
  border-radius: 12px;
  font-size: 15px;
  font-weight: 600;
  text-align: center;
  text-decoration: none;
  margin-top: 4px;
  cursor: pointer;
}

.expired-banner {
  background: rgba(224,82,82,.12);
  border: 1px solid rgba(224,82,82,.3);
  color: var(--red);
  border-radius: 10px;
  padding: 10px 14px;
  font-size: 13px;
  text-align: center;
  margin-bottom: 12px;
  width: 100%;
  max-width: 420px;
}

.no-dep {
  color: var(--muted);
  font-size: 14px;
  font-style: italic;
}
</style>
</head>
<body>

<?php if ($isExpired): ?>
<div class="expired-banner">This monitoring link has expired.</div>
<?php endif; ?>

<div class="card">
  <div class="route-label"><?= htmlspecialchars($routeLabel) ?></div>
  <div class="route-date"><?= htmlspecialchars($dateDisplay) ?></div>

  <hr class="divider">

  <div class="arrive-label">Time to arrival</div>
  <div class="arrive-time-target">Target: <strong><?= htmlspecialchars($arriveTime) ?></strong></div>
  <div class="countdown" id="arrivalCountdown">--:--:--</div>
  <div class="countdown-sub" id="arrivalSub"></div>
</div>

<div class="card">
  <div class="dep-row">
    <div class="dep-block">
      <div class="dep-label">Recommended departure</div>
      <?php if ($recDep): ?>
        <div class="dep-value" id="depTime"><?= htmlspecialchars($recDep) ?></div>
        <div class="dep-countdown" id="depCountdown"></div>
      <?php else: ?>
        <div class="no-dep" id="depTime">Calculating…</div>
        <div class="dep-countdown" id="depCountdown"></div>
      <?php endif; ?>
    </div>
    <?php if ($liveMin !== null): ?>
    <div class="dep-block" style="text-align:right">
      <div class="dep-label">Live estimate</div>
      <div class="live-row" style="justify-content:flex-end">
        <span class="live-min" id="liveMin"><?= $liveMin ?></span>
        <span>min</span>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <hr class="divider">

  <div class="dep-label" style="margin-bottom:8px">Alert stages</div>
  <div class="stages">
    <?php foreach ($allStages as $s): ?>
      <span class="stage-chip <?= in_array($s, $stagesFired) ? 'fired' : '' ?>">
        <?= $stageLabels[$s] ?>
      </span>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($gmapsUrl): ?>
<a href="<?= htmlspecialchars($gmapsUrl) ?>" target="_blank" rel="noopener noreferrer" class="nav-btn">
  🧭 Navigate
</a>
<?php endif; ?>

<div class="last-update" id="lastUpdate">
  <?= $lastCheck ? 'Updated ' . htmlspecialchars($lastCheck) : 'Awaiting first check…' ?>
</div>

<script>
const ARRIVE_TIME  = <?= json_encode($arriveTime) ?>;
const DATE         = <?= json_encode($date) ?>;
const IS_EXPIRED   = <?= json_encode($isExpired) ?>;
const TOKEN        = <?= json_encode($token) ?>;
const ALL_STAGES   = <?= json_encode($allStages) ?>;
const STAGE_LABELS = <?= json_encode($stageLabels) ?>;

// ─── Countdown logic ──────────────────────────────────────────────────────────

function parseLocalTime(dateStr, timeStr) {
  const [y, mo, d]  = dateStr.split('-').map(Number);
  const [h, m]      = timeStr.split(':').map(Number);
  return new Date(y, mo - 1, d, h, m, 0, 0);
}

const arrivalDt = parseLocalTime(DATE, ARRIVE_TIME);

function fmtDuration(totalSec) {
  const abs  = Math.abs(totalSec);
  const h    = Math.floor(abs / 3600);
  const m    = Math.floor((abs % 3600) / 60);
  const s    = abs % 60;
  const sign = totalSec < 0 ? '-' : '';
  if (h > 0) return `${sign}${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
  return `${sign}${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

function fmtRelative(totalSec) {
  const abs = Math.abs(totalSec);
  const m   = Math.floor(abs / 60);
  if (totalSec > 0)  return m <= 1 ? 'in less than a minute' : `in ${m} min`;
  if (totalSec === 0) return 'now';
  return m <= 1 ? 'just passed' : `${m} min ago`;
}

let currentRecDep = <?= json_encode($recDep) ?>;

function tick() {
  const now     = new Date();
  const toArriv = Math.round((arrivalDt - now) / 1000);

  // Arrival countdown
  const el = document.getElementById('arrivalCountdown');
  el.textContent = fmtDuration(toArriv);
  el.className   = 'countdown' + (toArriv < 0 ? ' overdue' : toArriv < 600 ? ' urgent' : '');
  document.getElementById('arrivalSub').textContent = fmtRelative(toArriv) + ' until arrival';

  // Departure countdown
  const depEl = document.getElementById('depCountdown');
  if (currentRecDep) {
    const depDt  = parseLocalTime(DATE, currentRecDep);
    const toDep  = Math.round((depDt - now) / 1000);
    depEl.textContent = fmtRelative(toDep) + ' to departure';
    depEl.className = 'dep-countdown' + (toDep < 0 ? ' overdue' : toDep < 600 ? ' urgent' : '');
  }
}

// ─── State refresh (every 30 s) ───────────────────────────────────────────────

async function refreshState() {
  if (IS_EXPIRED) return;
  try {
    const resp = await fetch(`monitor.php?token=${TOKEN}&json=1`);
    const data = await resp.json();
    if (data.error) return;

    // Update departure time
    if (data.recommended_departure && data.recommended_departure !== currentRecDep) {
      currentRecDep = data.recommended_departure;
      const depTimeEl = document.getElementById('depTime');
      if (depTimeEl) depTimeEl.textContent = data.recommended_departure;
    }

    // Update live min
    if (data.live_duration_min !== null) {
      const liveEl = document.getElementById('liveMin');
      if (liveEl) liveEl.textContent = data.live_duration_min;
    }

    // Update stages
    if (data.stages_fired) {
      document.querySelectorAll('.stage-chip').forEach((chip, i) => {
        const s = ALL_STAGES[i];
        chip.classList.toggle('fired', data.stages_fired.includes(s));
      });
    }

    // Update last check
    if (data.last_check) {
      const el = document.getElementById('lastUpdate');
      if (el) el.textContent = 'Updated ' + data.last_check;
    }

    if (data.is_expired) {
      clearInterval(refreshTimer);
    }
  } catch (e) {
    // silent — network hiccup
  }
}

tick();
setInterval(tick, 1000);

const refreshTimer = setInterval(refreshState, 30000);
</script>
</body>
</html>
<?php

// ─── Error page renderer ──────────────────────────────────────────────────────

function renderErrorPage(string $title, string $message): never
{
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> — Route Tracker</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: #0f1117; color: #e8eaf0;
  font-family: -apple-system, sans-serif;
  display: flex; align-items: center; justify-content: center;
  min-height: 100dvh; padding: 24px;
}
.box {
  text-align: center; max-width: 320px;
}
h1 { font-size: 22px; margin-bottom: 12px; }
p  { font-size: 14px; color: #7b82a0; line-height: 1.5; }
</style>
</head>
<body>
<div class="box">
  <h1><?= htmlspecialchars($title) ?></h1>
  <p><?= htmlspecialchars($message) ?></p>
</div>
</body>
</html><?php
    exit;
}
