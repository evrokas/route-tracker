<?php
/**
 * map.php — Route Tracker v2
 * Interactive map view for a stored collection.
 * Uses Leaflet.js (cdnjs) + OpenStreetMap tiles (free, no API key).
 *
 * URL: map.php?collection_id=42
 */

$baseDir = __DIR__;
require_once $baseDir . '/Config.php';
require_once $baseDir . '/auth.php';

Auth::requireLogin();

$collectionId = isset($_GET['collection_id']) ? (int)$_GET['collection_id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>🗺️ Route Map — Route Tracker</title>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">

<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:        #0f1117;
  --surface:   #1a1d27;
  --border:    #2e3350;
  --text:      #e2e8f0;
  --muted:     #64748b;
  --accent:    #5b7cf6;
  --c0:        #5b7cf6;  /* primary route  — blue   */
  --c1:        #f59e0b;  /* alt 1          — amber  */
  --c2:        #10b981;  /* alt 2          — green  */
  --c3:        #ef4444;  /* alt 3          — red    */
}

body {
  font-family: 'Segoe UI', system-ui, sans-serif;
  background: var(--bg);
  color: var(--text);
  height: 100vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* ── Header ──────────────────────────────────────────────────────────────── */
.header {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 10px 18px;
  display: flex;
  align-items: center;
  gap: 14px;
  flex-shrink: 0;
  flex-wrap: wrap;
}
.back-btn {
  background: none;
  border: 1px solid var(--border);
  color: var(--text);
  border-radius: 7px;
  padding: 5px 12px;
  cursor: pointer;
  font-size: 13px;
  font-family: inherit;
  white-space: nowrap;
}
.back-btn:hover { background: var(--border); }

.header-info { flex: 1; min-width: 0; }
.header-title {
  font-size: 15px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.header-sub {
  font-size: 11px;
  color: var(--muted);
  margin-top: 2px;
}

.sibling-wrap { display: flex; align-items: center; gap: 8px; }
.sibling-wrap label { font-size: 12px; color: var(--muted); white-space: nowrap; }
.sibling-wrap select {
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--text);
  border-radius: 7px;
  padding: 5px 10px;
  font-size: 12px;
  font-family: inherit;
  cursor: pointer;
}

/* ── Body layout ─────────────────────────────────────────────────────────── */
.body {
  display: flex;
  flex: 1;
  overflow: hidden;
}

/* ── Map ──────────────────────────────────────────────────────────────────── */
#map {
  flex: 1;
  z-index: 0;
}

/* Force Leaflet tiles to respect dark theme by layering a tint */
.map-dark-overlay {
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: 400;
  background: rgba(15,17,23,0.18);
}

/* ── Side panel ──────────────────────────────────────────────────────────── */
.panel {
  width: 300px;
  flex-shrink: 0;
  background: var(--surface);
  border-left: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.panel-section {
  padding: 14px 16px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.panel-section h3 {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--muted);
  margin-bottom: 10px;
}

/* Route legend */
.legend-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 8px;
  border-radius: 7px;
  cursor: pointer;
  transition: background .12s;
  margin-bottom: 3px;
}
.legend-item:hover { background: rgba(255,255,255,.05); }
.legend-item.active { background: rgba(91,124,246,.12); }
.legend-swatch {
  width: 18px;
  height: 5px;
  border-radius: 3px;
  flex-shrink: 0;
}
.legend-name {
  font-size: 13px;
  font-weight: 600;
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.legend-dur {
  font-size: 12px;
  color: var(--muted);
  white-space: nowrap;
}
.legend-badge {
  font-size: 10px;
  background: var(--accent);
  color: #fff;
  border-radius: 10px;
  padding: 1px 6px;
  flex-shrink: 0;
}

/* Steps list */
.steps-scroll {
  overflow-y: auto;
  flex: 1;
  padding: 8px 8px 16px;
}
.step-item {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 8px 8px;
  border-radius: 7px;
  cursor: pointer;
  transition: background .1s;
}
.step-item:hover { background: rgba(255,255,255,.04); }
.step-item.highlighted { background: rgba(91,124,246,.15); }
.step-num {
  background: var(--border);
  color: var(--muted);
  font-size: 10px;
  font-weight: 700;
  border-radius: 50%;
  width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-top: 1px;
}
.step-text {
  font-size: 12px;
  line-height: 1.45;
  flex: 1;
}
.step-meta {
  font-size: 10px;
  color: var(--muted);
  margin-top: 2px;
}

/* Loading overlay */
.loading-overlay {
  position: absolute;
  inset: 0;
  background: var(--bg);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  flex-direction: column;
  gap: 14px;
  font-size: 14px;
  color: var(--muted);
}
.spinner {
  width: 28px; height: 28px;
  border: 3px solid var(--border);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin .8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.error-msg {
  color: #fca5a5;
  background: #450a0a;
  border: 1px solid #7f1d1d;
  border-radius: 8px;
  padding: 12px 16px;
  font-size: 13px;
  margin: 20px;
}

@media (max-width: 640px) {
  .panel { display: none; }
}
</style>
</head>
<body>

<!-- Loading overlay -->
<div class="loading-overlay" id="loadingOverlay">
  <div class="spinner"></div>
  <span>Loading route data…</span>
</div>

<!-- Header -->
<div class="header">
  <button class="back-btn" onclick="history.back()">← Back</button>

  <div class="header-info">
    <div class="header-title" id="headerTitle">Loading…</div>
    <div class="header-sub"  id="headerSub"></div>
  </div>

  <div class="sibling-wrap">
    <label for="siblingSelect">Date:</label>
    <select id="siblingSelect" onchange="loadCollection(this.value)"></select>
  </div>
</div>

<!-- Body -->
<div class="body">
  <div id="map">
    <div class="map-dark-overlay"></div>
  </div>

  <div class="panel">
    <div class="panel-section">
      <h3>Routes</h3>
      <div id="legend"></div>
    </div>
    <div class="panel-section" style="padding-bottom:6px;">
      <h3 id="stepsHeading">Turn-by-turn</h3>
    </div>
    <div class="steps-scroll" id="stepsList"></div>
  </div>
</div>

<!-- Leaflet JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>

<script>
// ── Config ────────────────────────────────────────────────────────────────────
const API_BASE    = 'api.php';
const COLORS      = ['#5b7cf6', '#f59e0b', '#10b981', '#ef4444', '#a855f7', '#06b6d4'];
const WEIGHT_ACT  = 5;
const WEIGHT_INK  = 3;
const OPACITY_ACT = 0.92;
const OPACITY_INK = 0.45;

// ── State ─────────────────────────────────────────────────────────────────────
let map          = null;
let polylines    = [];   // [{layer, routeData}]
let stepMarkers  = [];
let activeRoute  = 0;

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Init map centred on Athens as default (will be re-fitted after data loads)
  map = L.map('map', { zoomControl: true }).setView([37.98, 23.73], 12);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 19,
  }).addTo(map);

  // Read collection_id from URL
  const params = new URLSearchParams(window.location.search);
  const collId = params.get('collection_id');
  if (!collId) {
    showError('No collection_id in URL. Open this page from the History tab.');
    return;
  }
  loadCollection(collId);
});

// ── Load collection data ──────────────────────────────────────────────────────
async function loadCollection(collId) {
  document.getElementById('loadingOverlay').style.display = 'flex';
  clearMap();

  try {
    const resp = await fetch(
      `${API_BASE}?action=route_map&collection_id=${collId}`,
      { credentials: 'same-origin' }
    );
    if (resp.status === 401) { window.location.href = 'login.php'; return; }
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    const data = await resp.json();
    if (data.error) throw new Error(data.error);

    // Update URL without reload
    const url = new URL(window.location);
    url.searchParams.set('collection_id', collId);
    window.history.replaceState({}, '', url);

    render(data);
  } catch (e) {
    showError('Failed to load route data: ' + e.message);
  } finally {
    document.getElementById('loadingOverlay').style.display = 'none';
  }
}

// ── Render ────────────────────────────────────────────────────────────────────
function render(data) {
  const { collection, routes, siblings } = data;

  // Header
  const dt      = new Date(collection.collected_at);
  const dayName = dt.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' });
  const time    = dt.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
  document.getElementById('headerTitle').textContent = collection.route_label || collection.route_id;
  document.getElementById('headerSub').textContent   =
    `${dayName} at ${time}  ·  Scheduled: ${collection.schedule_mode} ${collection.scheduled_time}`;

  // Sibling selector
  const sel = document.getElementById('siblingSelect');
  sel.innerHTML = '';
  siblings.forEach(s => {
    const d    = new Date(s.collected_at);
    const label= d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })
                 + ' ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
                 + (s.primary_min ? ` (${s.primary_min}m)` : '');
    const opt  = new Option(label, s.id);
    if (String(s.id) === String(collection.id)) opt.selected = true;
    sel.add(opt);
  });

  // Draw routes
  const bounds = [];
  routes.forEach((route, i) => {
    if (!route.polyline || route.polyline.length === 0) return;
    const color = COLORS[i] || COLORS[COLORS.length - 1];

    const poly = L.polyline(route.polyline, {
      color,
      weight:  i === 0 ? WEIGHT_ACT : WEIGHT_INK,
      opacity: i === 0 ? OPACITY_ACT : OPACITY_INK,
      lineJoin: 'round',
      lineCap:  'round',
    }).addTo(map);

    poly.on('click', () => activateRoute(i));

    const dur = route.duration_in_traffic_seconds ?? route.duration_seconds;
    const tip = `<strong>${route.summary || 'Route ' + (i+1)}</strong><br>${fmtDur(dur)} · ${fmtDist(route.distance_meters)}`;
    poly.bindTooltip(tip, { sticky: true });

    polylines.push({ layer: poly, route, color });
    route.polyline.forEach(pt => bounds.push(pt));
  });

  if (bounds.length) map.fitBounds(bounds, { padding: [30, 30] });

  // Add start/end markers
  if (routes[0]?.polyline?.length) {
    const pts   = routes[0].polyline;
    const start = pts[0];
    const end   = pts[pts.length - 1];
    addMarker(start, '🟢', collection.origin   || 'Start');
    addMarker(end,   '🔴', collection.destination || 'End');
  }

  // Legend
  buildLegend(routes);

  // Show steps for primary route
  activateRoute(0);
}

function activateRoute(idx) {
  activeRoute = idx;

  // Update polyline weights
  polylines.forEach((p, i) => {
    p.layer.setStyle({
      weight:  i === idx ? WEIGHT_ACT : WEIGHT_INK,
      opacity: i === idx ? OPACITY_ACT : OPACITY_INK,
    });
    if (i === idx) p.layer.bringToFront();
  });

  // Update legend
  document.querySelectorAll('.legend-item').forEach((el, i) => {
    el.classList.toggle('active', i === idx);
  });

  // Show steps
  if (polylines[idx]) buildSteps(polylines[idx].route, polylines[idx].color);
}

// ── Legend ────────────────────────────────────────────────────────────────────
function buildLegend(routes) {
  const el = document.getElementById('legend');
  el.innerHTML = '';

  routes.forEach((route, i) => {
    const color = COLORS[i] || COLORS[COLORS.length - 1];
    const dur   = route.duration_in_traffic_seconds ?? route.duration_seconds;
    const item  = document.createElement('div');
    item.className = 'legend-item' + (i === 0 ? ' active' : '');
    item.innerHTML = `
      <div class="legend-swatch" style="background:${color}"></div>
      <div class="legend-name">${route.summary || 'Route ' + (i+1)}</div>
      <div class="legend-dur">${fmtDur(dur)}</div>
      ${i === 0 ? '<div class="legend-badge">Primary</div>' : ''}
    `;
    item.onclick = () => activateRoute(i);
    el.appendChild(item);
  });
}

// ── Steps panel ───────────────────────────────────────────────────────────────
function buildSteps(route, color) {
  const heading = document.getElementById('stepsHeading');
  heading.textContent = `Turn-by-turn — ${route.summary || 'Route'}`;

  clearStepMarkers();
  const list = document.getElementById('stepsList');
  list.innerHTML = '';

  if (!route.steps || route.steps.length === 0) {
    list.innerHTML = '<div style="color:var(--muted);font-size:12px;padding:12px">No step data available</div>';
    return;
  }

  route.steps.forEach((step, i) => {
    // Step marker on map
    if (step.start_lat !== null) {
      const marker = L.circleMarker([step.start_lat, step.start_lng], {
        radius: 5, color, fillColor: color, fillOpacity: 0.8, weight: 2,
      }).addTo(map);
      marker.bindTooltip(`Step ${i+1}: ${step.instruction || ''}`, { sticky: true });
      marker.on('click', () => highlightStep(i));
      stepMarkers.push(marker);
    }

    // Step row in panel
    const row = document.createElement('div');
    row.className = 'step-item';
    row.id = `step-${i}`;
    row.innerHTML = `
      <div class="step-num">${i + 1}</div>
      <div class="step-text">
        ${step.instruction || '—'}
        <div class="step-meta">${fmtDist(step.distance_meters)} · ${fmtDur(step.duration_seconds)}</div>
      </div>
    `;
    row.onclick = () => {
      highlightStep(i);
      if (step.start_lat !== null) {
        map.setView([step.start_lat, step.start_lng], 16, { animate: true });
      }
    };
    list.appendChild(row);
  });
}

function highlightStep(idx) {
  document.querySelectorAll('.step-item').forEach((el, i) => {
    el.classList.toggle('highlighted', i === idx);
  });
  document.getElementById(`step-${idx}`)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function addMarker(latlng, emoji, tooltip) {
  const icon = L.divIcon({
    html: `<div style="font-size:22px;line-height:1;filter:drop-shadow(0 1px 3px #000)">${emoji}</div>`,
    iconSize: [26, 26], iconAnchor: [13, 13], className: '',
  });
  L.marker(latlng, { icon }).bindTooltip(tooltip).addTo(map);
}

function clearMap() {
  polylines.forEach(p => map && map.removeLayer(p.layer));
  polylines = [];
  clearStepMarkers();
  document.getElementById('legend').innerHTML    = '';
  document.getElementById('stepsList').innerHTML = '';
  document.getElementById('headerTitle').textContent = 'Loading…';
  document.getElementById('headerSub').textContent   = '';
}

function clearStepMarkers() {
  stepMarkers.forEach(m => map && map.removeLayer(m));
  stepMarkers = [];
}

function fmtDur(sec) {
  if (!sec) return '—';
  const m = Math.round(sec / 60);
  return m >= 60 ? `${Math.floor(m/60)}h ${m%60}m` : `${m} min`;
}

function fmtDist(m) {
  if (!m) return '';
  return m >= 1000 ? `${(m/1000).toFixed(1)} km` : `${m} m`;
}

function showError(msg) {
  document.getElementById('loadingOverlay').style.display = 'none';
  document.getElementById('map').innerHTML =
    `<div class="map-dark-overlay"></div><div class="error-msg">⚠ ${msg}</div>`;
}
</script>
</body>
</html>