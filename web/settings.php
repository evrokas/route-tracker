<?php
/**
 * settings.php — Route Tracker v3
 * Multi-tab admin settings UI.
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

// PHP timezone list for dropdown
$timezones = DateTimeZone::listIdentifiers();
$currentTz = $config->getTimezone();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>⚙️ Route Tracker — Settings</title>
<link rel="stylesheet" href="css/dashboard.css">
<link rel="stylesheet" href="css/settings.css">
</head>
<body>

<div class="header">
  <h1>⚙️ Route <span>Tracker</span> — Settings</h1>
  <div class="header-controls">
    <a href="dashboard.php" class="btn-link">← Dashboard</a>
    <a href="?logout=1" style="font-size:12px;color:var(--muted);text-decoration:none;padding:6px 10px;">Sign out</a>
  </div>
</div>

<div class="tab-bar">
  <button class="tab active" data-tab="general">General</button>
  <button class="tab" data-tab="routes">Routes</button>
  <button class="tab" data-tab="alerts">Alerts</button>
  <button class="tab" data-tab="system">System</button>
</div>

<div class="settings-content" id="settingsContent">
  <div class="loading"><div class="spinner"></div>Loading…</div>
</div>

<!-- ─── Templates ─────────────────────────────────────────────────────────── -->

<template id="tplGeneral">
  <div class="settings-section">
    <div class="section-title">Google Maps API</div>
    <div class="field-group">
      <label>API Key</label>
      <div class="field-row">
        <input type="password" id="google_maps_api_key" autocomplete="off" placeholder="AIza…">
        <button class="btn-secondary" onclick="testApiKey()">Test API</button>
      </div>
      <div class="field-hint">Required for data collection and advisor</div>
    </div>
    <div class="field-row-2">
      <div class="field-group">
        <label>Language</label>
        <input type="text" id="google_maps_language" placeholder="el" maxlength="5">
      </div>
      <div class="field-group">
        <label>Region</label>
        <input type="text" id="google_maps_region" placeholder="gr" maxlength="5">
      </div>
    </div>
  </div>

  <div class="settings-section">
    <div class="section-title">Application URL</div>
    <div class="field-group">
      <label>Base URL</label>
      <input type="url" id="app_url" placeholder="https://example.com/apps/tracker">
      <div class="field-hint">Used to build monitoring links sent in Telegram alerts. Leave empty to disable monitoring links.</div>
    </div>
  </div>

  <div class="settings-section">
    <div class="section-title">Timezone &amp; Collection</div>
    <div class="field-group">
      <label>Timezone</label>
      <select id="timezone">
        <?php foreach ($timezones as $tz): ?>
        <option value="<?= htmlspecialchars($tz) ?>"<?= $tz === $currentTz ? ' selected' : '' ?>><?= htmlspecialchars($tz) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field-row-2">
      <div class="field-group">
        <label>Window Before (min)</label>
        <input type="number" id="window_before_minutes" min="0" max="120">
      </div>
      <div class="field-group">
        <label>Window After (min)</label>
        <input type="number" id="window_after_minutes" min="0" max="30">
      </div>
    </div>
    <div class="field-group">
      <label class="checkbox-label">
        <input type="checkbox" id="request_alternatives">
        Request alternative routes from Google Maps
      </label>
    </div>
  </div>

  <div class="settings-section">
    <div class="section-title">Change Password</div>
    <div class="field-group">
      <label>Current Password</label>
      <input type="password" id="pw_current" autocomplete="current-password">
    </div>
    <div class="field-row-2">
      <div class="field-group">
        <label>New Password</label>
        <input type="password" id="pw_new" autocomplete="new-password">
      </div>
      <div class="field-group">
        <label>Confirm New Password</label>
        <input type="password" id="pw_confirm" autocomplete="new-password">
      </div>
    </div>
    <button class="btn-secondary" onclick="changePassword()">Change Password</button>
  </div>

  <div class="settings-footer">
    <button class="btn-primary" onclick="saveGeneral()">Save General Settings</button>
    <span id="generalStatus" class="save-status"></span>
  </div>
</template>

<template id="tplRoutes">
  <div class="settings-section">
    <div class="section-title-row">
      <span class="section-title">Routes</span>
      <button class="btn-primary" onclick='showRouteForm(null)'>+ Add Route</button>
    </div>
    <div id="routesList"></div>
  </div>
  <div id="routeFormWrap" style="display:none"></div>
</template>

<template id="tplAlerts">
  <!-- Global Thresholds -->
  <div class="settings-section">
    <div class="section-title">Global Thresholds</div>
    <div class="field-row-3">
      <div class="field-group">
        <label>Traffic Threshold (%)</label>
        <input type="number" id="alert_traffic_threshold" min="5" max="200">
        <div class="field-hint">Alert when current > avg × (1 + threshold%)</div>
      </div>
      <div class="field-group">
        <label>Min Samples for Alerts</label>
        <input type="number" id="alert_min_samples" min="1" max="100">
      </div>
      <div class="field-group">
        <label>Max Alerts per Day</label>
        <input type="number" id="alert_max_per_day" min="1" max="20">
      </div>
    </div>
    <div class="settings-footer" style="margin-top:12px;padding-top:0;border:none">
      <button class="btn-primary" onclick="saveAlertThresholds()">Save Thresholds</button>
      <span id="alertStatus" class="save-status"></span>
    </div>
  </div>

  <!-- Channel Profiles -->
  <div class="settings-section">
    <div class="section-title">Channel Profiles</div>
    <div class="field-hint" style="margin-bottom:16px">
      Named, reusable credentials for each messaging channel. Routes send alerts via Alert Profiles, which bundle one or more channel profiles.
    </div>

    <div class="channel-profiles-block">
      <div class="cp-type-header">
        <span>✈️ Telegram</span>
        <button class="btn-tiny" onclick="addChannelProfile('telegram')">+ Add</button>
      </div>
      <div id="cpTable-telegram"></div>
      <div id="cpForm-telegram" style="display:none"></div>
    </div>

    <div class="channel-profiles-block">
      <div class="cp-type-header">
        <span>📧 Email (SMTP)</span>
        <button class="btn-tiny" onclick="addChannelProfile('email')">+ Add</button>
      </div>
      <div id="cpTable-email"></div>
      <div id="cpForm-email" style="display:none"></div>
    </div>

    <div class="channel-profiles-block">
      <div class="cp-type-header">
        <span>🔐 Signal</span>
        <button class="btn-tiny" onclick="addChannelProfile('signal')">+ Add</button>
      </div>
      <div id="cpTable-signal"></div>
      <div id="cpForm-signal" style="display:none"></div>
    </div>

    <div class="channel-profiles-block">
      <div class="cp-type-header">
        <span>💬 Viber</span>
        <button class="btn-tiny" onclick="addChannelProfile('viber')">+ Add</button>
      </div>
      <div id="cpTable-viber"></div>
      <div id="cpForm-viber" style="display:none"></div>
    </div>
  </div>

  <!-- Alert Profiles -->
  <div class="settings-section">
    <div class="section-title-row">
      <span class="section-title">Alert Profiles</span>
      <button class="btn-primary" onclick="addAlertProfile()">+ Add Profile</button>
    </div>
    <div class="field-hint" style="margin-bottom:12px">
      Bundle multiple channel profiles. Routes are assigned to alert profiles, not individual channels.
    </div>
    <div id="alertProfilesTable"></div>
    <div id="alertProfileForm" style="display:none"></div>
  </div>
</template>

<template id="tplSystem">
  <div class="settings-section">
    <div class="section-title">Manual Triggers</div>
    <div class="field-row">
      <select id="testRouteId">
        <option value="">— Select route —</option>
      </select>
      <button class="btn-secondary" onclick="runTestCollection()">Run Test Collection</button>
      <button class="btn-secondary" onclick="runAdvisor()">Run Advisor Now</button>
    </div>
    <div id="testResult" class="log-box" style="display:none"></div>
  </div>

  <div class="settings-section">
    <div class="section-title">Database Stats</div>
    <div id="dbStats" class="stats-grid"></div>
    <button class="btn-secondary" style="margin-top:12px" onclick="exportTrips()">Export Trips CSV</button>
  </div>

  <div class="settings-section">
    <div class="section-title">Backup &amp; Restore</div>
    <p class="field-hint" style="margin-bottom:14px">
      The backup file is a JSON snapshot of all settings and routes (including API keys and credentials).
      Store it securely.
    </p>
    <div class="field-row">
      <button class="btn-primary" onclick="exportConfig()">⬇ Download Config Backup</button>
    </div>
    <div style="margin-top:20px">
      <label class="label">Restore from backup file</label>
      <div class="field-row" style="margin-top:8px">
        <input type="file" id="configBackupFile" accept=".json" style="flex:1;color:var(--text);background:var(--card);border:1px solid var(--border);border-radius:8px;padding:6px 10px;font-size:13px">
        <button class="btn-secondary btn-danger" onclick="importConfig()">Restore</button>
      </div>
      <div class="field-hint" style="margin-top:6px">Warning: restoring overwrites all current settings and routes.</div>
      <div id="importStatus" class="save-status" style="margin-top:8px"></div>
    </div>
  </div>

  <div class="settings-section">
    <div class="section-title-row log-header">
      <span class="section-title">Recent Logs</span>
      <div class="log-tabs">
        <button class="log-tab-btn active" onclick="loadLog('collector', this)">Collector</button>
        <button class="log-tab-btn" onclick="loadLog('alerts', this)">Alerts</button>
        <button class="log-tab-btn" onclick="loadLog('advisor', this)">Advisor</button>
      </div>
    </div>
    <div id="logBox" class="log-box"></div>
  </div>
</template>

<script>
  const API_BASE = <?= json_encode($apiBase) ?>;
</script>
<script src="js/settings.js"></script>
</body>
</html>
