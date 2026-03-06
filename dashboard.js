// ═══════════════════════════════════════════════════════════════════════════
// State
// ═══════════════════════════════════════════════════════════════════════════
let state = {
  tab:        'advisor',
  routeId:    '',
  year:       '',
  month:      '',
  timeWindow: 'all',
  routes:     [],
  cache:      {},
};

// ═══════════════════════════════════════════════════════════════════════════
// API helper
// ═══════════════════════════════════════════════════════════════════════════
async function api(params = {}) {
  const p = new URLSearchParams(params);
  for (const [k, v] of [...p.entries()]) {
    if (v === '' || v === null || v === undefined) p.delete(k);
  }
  const url = `${API_BASE}?${p}`;
  if (state.cache[url]) return state.cache[url];

  let resp;
  try {
    resp = await fetch(url, { credentials: 'same-origin' });
  } catch (e) {
    throw new Error(`Cannot reach api.php — is the web server running? (${e.message})`);
  }

  const text = await resp.text();

  if (!resp.ok) {
    if (resp.status === 401) {
      window.location.href = 'login.php';
      return;
    }
    let msg = resp.statusText;
    try { msg = JSON.parse(text).error || msg; } catch (_) {}
    if (resp.status === 404) msg = 'api.php not found — check your web server document root';
    throw new Error(msg);
  }

  let data;
  try {
    data = JSON.parse(text);
  } catch (_) {
    const jsonStart = text.indexOf('{');
    if (jsonStart > 0) {
      console.warn('api.php had non-JSON prefix (PHP notice?):', text.substring(0, jsonStart));
      data = JSON.parse(text.substring(jsonStart));
    } else {
      throw new Error(`api.php returned non-JSON. Check PHP error logs.\nFirst 200 chars: ${text.substring(0, 200)}`);
    }
  }

  state.cache[url] = data;
  return data;
}

function clearCache() { state.cache = {}; }

// ═══════════════════════════════════════════════════════════════════════════
// Filters
// ═══════════════════════════════════════════════════════════════════════════
function filters() {
  const f = { year: state.year, month: state.month };
  if (state.routeId) f.route_id = state.routeId;
  return f;
}

// ═══════════════════════════════════════════════════════════════════════════
// Init
// ═══════════════════════════════════════════════════════════════════════════
async function init() {
  const selYear = document.getElementById('selYear');
  const cur = new Date().getFullYear();
  for (let y = cur; y >= cur - 3; y--) {
    selYear.add(new Option(y, y));
  }

  document.querySelectorAll('.tab').forEach(t => {
    t.addEventListener('click', () => {
      document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
      t.classList.add('active');
      state.tab = t.dataset.tab;
      render();
    });
  });

  document.getElementById('selYear').addEventListener('change', e => {
    state.year = e.target.value; clearCache(); render();
  });
  document.getElementById('selMonth').addEventListener('change', e => {
    state.month = e.target.value; clearCache(); render();
  });

  // Time-window chips (advisor tab only)
  document.querySelectorAll('.time-chip').forEach(c => {
    c.addEventListener('click', () => {
      document.querySelectorAll('.time-chip').forEach(x => x.classList.remove('active'));
      c.classList.add('active');
      state.timeWindow = c.dataset.tw;
      render();
    });
  });

  try {
    const d = await api({ action: 'route_list' });
    state.routes = d.routes || [];
    buildChips();
  } catch (e) {
    console.warn('Could not load route list for chips:', e.message);
  }

  render();
}

// ═══════════════════════════════════════════════════════════════════════════
// Route chips
// ═══════════════════════════════════════════════════════════════════════════
function buildChips() {
  const bar = document.getElementById('routeBar');
  bar.innerHTML = '<div class="chip active" data-id="">All Routes</div>';
  state.routes.forEach(r => {
    const c = document.createElement('div');
    c.className  = 'chip';
    c.dataset.id = r.id;
    c.textContent = r.label;
    bar.appendChild(c);
  });
  bar.querySelectorAll('.chip').forEach(c => {
    c.addEventListener('click', () => {
      bar.querySelectorAll('.chip').forEach(x => x.classList.remove('active'));
      c.classList.add('active');
      state.routeId = c.dataset.id;
      clearCache();
      render();
    });
  });
}

// ═══════════════════════════════════════════════════════════════════════════
// Render dispatcher
// ═══════════════════════════════════════════════════════════════════════════
async function render() {
  setStatus('loading');
  updateFilterBadge();

  // Show time bar only on the advisor tab
  const timeBar = document.getElementById('timeBar');
  if (timeBar) timeBar.style.display = state.tab === 'advisor' ? '' : 'none';

  const box = document.getElementById('content');
  box.innerHTML = '<div class="loading"><div class="spinner"></div>Loading…</div>';

  try {
    switch (state.tab) {
      case 'advisor':  await renderAdvisor(box);  break;
      case 'overview': await renderOverview(box); break;
      case 'best':     await renderBest(box);     break;
      case 'byday':    await renderByDay(box);    break;
      case 'trends':   await renderTrends(box);   break;
      case 'history':  await renderHistory(box);  break;
    }
    setStatus('ok');
  } catch (e) {
    box.innerHTML = `<div class="empty">⚠ ${e.message}</div>`;
    setStatus('err');
  }
}

function refresh() { clearCache(); render(); }

function updateFilterBadge() {
  const badge = document.getElementById('filterBadge');
  if (!badge) return;
  if (state.routeId) {
    const route = state.routes.find(r => r.id === state.routeId);
    const label = route ? route.label : state.routeId;
    badge.textContent = 'Filtered: ' + label;
    badge.style.display = 'inline-block';
  } else {
    badge.style.display = 'none';
  }
}

function setStatus(s) {
  const dot  = document.getElementById('statusDot');
  const text = document.getElementById('statusText');
  if (s === 'loading') { dot.className = 'status-dot';     text.textContent = 'Loading…'; }
  if (s === 'ok')      { dot.className = 'status-dot ok';  text.textContent = new Date().toLocaleTimeString(); }
  if (s === 'err')     { dot.className = 'status-dot err'; text.textContent = 'Error'; }
}

// ═══════════════════════════════════════════════════════════════════════════
// Format helpers
// ═══════════════════════════════════════════════════════════════════════════
const sec2min = s => s != null ? (s / 60).toFixed(1) + ' min' : '–';
const dayName = d => ['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'][+d] || d;

function badgeMode(mode) {
  const cls = mode === 'arrive' ? 'badge-arrive' : 'badge-depart';
  return `<span class="mini-badge ${cls}">${mode}</span>`;
}

function gmapsLink(origin, destination) {
  if (!origin || !destination) return '';
  const url = `https://www.google.com/maps/dir/?api=1&origin=${encodeURIComponent(origin)}&destination=${encodeURIComponent(destination)}&travelmode=driving`;
  return `<a href="${url}" target="_blank" title="Navigate in Google Maps"
             style="text-decoration:none;font-size:16px;opacity:.7;margin-left:6px;">🧭</a>`;
}

// ═══════════════════════════════════════════════════════════════════════════
// TAB: Advisor
// ═══════════════════════════════════════════════════════════════════════════
async function renderAdvisor(box) {
  const d       = await api({ action: 'advisor_status', ...filters() });
  const allRows = d.advisor || [];

  if (!allRows.length) {
    box.innerHTML = `
      <div class="empty">
        No advisor-enabled routes yet.<br>
        Go to <a href="settings.php">⚙️ Settings → Routes</a> to configure a route with the advisor enabled.
      </div>`;
    return;
  }

  // Apply time-window filter
  const tw = state.timeWindow || 'all';
  let rows = allRows;

  if (tw === 'today') {
    rows = allRows.filter(r => r.runs_today);
  } else if (tw === 'upcoming') {
    rows = allRows.filter(r => r.runs_today && r.until_arrival_min > -15);
  } else if (tw === 'next3h') {
    rows = allRows.filter(r => r.runs_today && r.until_arrival_min > -15 && r.until_arrival_min <= 180);
  } else if (tw === 'next') {
    const upcoming = allRows.filter(r => r.runs_today && r.until_arrival_min > -15);
    upcoming.sort((a, b) => a.until_arrival_min - b.until_arrival_min);
    rows = upcoming.slice(0, 1);
  }

  if (!rows.length) {
    const labels = { today: 'today', upcoming: 'upcoming', next3h: 'within 3 hours', next: 'next event' };
    box.innerHTML = `<div class="empty">No advisor events ${labels[tw] || ''} match the current filter.</div>`;
    return;
  }

  const allStages = ['planning', 'window', 'reminder', 'urgent', 'last_call'];

  let html = '<div class="cards-grid">';

  for (const r of rows) {
    const liveMins = r.live_duration_seconds != null
      ? Math.round(r.live_duration_seconds / 60)
      : null;

    const recDep = r.recommended_departure || '–';

    let countdownHtml = '';
    if (r.until_departure_min != null) {
      const m = r.until_departure_min;
      if (m > 0) {
        countdownHtml = `<span class="advisor-countdown">in ${m} min</span>`;
      } else if (m >= -5) {
        countdownHtml = `<span class="advisor-countdown urgent">now!</span>`;
      } else {
        countdownHtml = `<span class="advisor-countdown muted">${Math.abs(m)} min ago</span>`;
      }
    }

    const stagesHtml = allStages
      .filter(s => (r.enabled_stages || []).includes(s))
      .map(s => {
        const fired = (r.stages_fired || []).includes(s);
        return `<span class="advisor-stage ${fired ? 'fired' : 'pending'}" title="${s}">
          ${fired ? '✓' : '•'} ${s.replace('_', ' ')}
        </span>`;
      }).join('');

    const lastCheck = r.last_check
      ? r.last_check.substring(11, 16)
      : null;

    const monitorLink = r.monitor_url
      ? `<a href="${r.monitor_url}" target="_blank" rel="noopener noreferrer" class="card-link" title="Open monitoring page">📊</a>`
      : '';

    html += `
      <div class="card advisor-card">
        <div class="card-header">
          <span class="card-title">${r.route_label}</span>
          <span class="card-badge badge-arrive">${r.days ? r.days + ' · ' : ''}arrive ${r.arrive_time}</span>
          ${monitorLink}${gmapsLink(r.origin, r.destination)}
        </div>
        <div class="advisor-departure">
          <span class="advisor-leave-label">Leave by</span>
          <span class="advisor-leave-time">${recDep}</span>
          ${countdownHtml}
        </div>
        ${liveMins !== null ? `
        <div class="card-unit">Live: ${liveMins} min${r.last_check ? ` · checked ${lastCheck}` : ''}</div>` : `
        <div class="card-unit muted-text">No live data yet today</div>`}
        <div class="advisor-stages">${stagesHtml}</div>
      </div>`;
  }

  html += '</div>';
  html += '<div class="empty" style="font-size:12px;margin-top:8px;color:var(--muted)">Updated by advisor.php every 5 min via cron</div>';

  box.innerHTML = html;
}

// ═══════════════════════════════════════════════════════════════════════════
// TAB: Overview
// ═══════════════════════════════════════════════════════════════════════════
async function renderOverview(box) {
  const d = await api({ action: 'overview', ...filters() });
  const rows = d.overview || [];

  if (!rows.length) {
    box.innerHTML = '<div class="empty">No data yet. Start collecting routes first.</div>';
    return;
  }

  let html = '<div class="cards-grid">';
  for (const r of rows) {
    const matchedSched = (r.schedule || []).find(s =>
      r.schedule_mode === 'arrive' ? s.arrive === r.scheduled_time : s.depart === r.scheduled_time
    );
    const daysLabel = matchedSched?.days || '';
    const badge = r.schedule_mode === 'arrive'
      ? `<span class="card-badge badge-arrive">${daysLabel ? daysLabel + ' · ' : ''}arrive ${r.scheduled_time}</span>`
      : `<span class="card-badge badge-depart">${daysLabel ? daysLabel + ' · ' : ''}depart ${r.scheduled_time}</span>`;

    const schedHtml = (r.schedule || []).map(s => {
      const t = s.arrive || s.depart || '';
      const m = s.arrive ? 'arrive' : 'depart';
      return `<div class="schedule-tag">${s.days} ${m} ${t}</div>`;
    }).join('');

    html += `
      <div class="card">
        <div class="card-header">
          <span class="card-title">${r.route_label || r.route_id}</span>
          ${badge}
          ${gmapsLink(r.origin, r.destination)}
        </div>
        <div class="card-value">${sec2min(r.avg_duration)}</div>
        <div class="card-unit">average travel time · ${r.total_collections} samples</div>
        <div class="card-stats">
          <div class="stat-item"><label>Best</label><span class="stat-best">${sec2min(r.min_duration)}</span></div>
          <div class="stat-item"><label>Worst</label><span class="stat-worst">${sec2min(r.max_duration)}</span></div>
          <div class="stat-item"><label>Range</label><span>${sec2min(r.max_duration - r.min_duration)}</span></div>
        </div>
        ${schedHtml ? `<div class="schedule-table"><div class="schedule-row">${schedHtml}</div></div>` : ''}
      </div>`;
  }
  html += '</div>';

  html += '<div class="section"><div class="section-title">Average Duration by Route &amp; Schedule</div>';
  html += '<div class="chart-wrap"><canvas id="chartOverview" height="220"></canvas></div></div>';

  box.innerHTML = html;

  SimpleChart.bar('chartOverview',
    rows.map(r => (r.route_label || r.route_id) + ' ' + (r.scheduled_time || '')),
    [
      { label: 'Average', data: rows.map(r => +(r.avg_duration / 60).toFixed(1)), color: '#5b7cf6' },
      { label: 'Best',    data: rows.map(r => +(r.min_duration / 60).toFixed(1)), color: '#34d399' },
      { label: 'Worst',   data: rows.map(r => +(r.max_duration / 60).toFixed(1)), color: '#f87171' },
    ],
    { yLabel: 'minutes' }
  );
}

// ═══════════════════════════════════════════════════════════════════════════
// TAB: Best Routes
// ═══════════════════════════════════════════════════════════════════════════
async function renderBest(box) {
  const d = await api({ action: 'best_routes', ...filters() });
  const rows = d.best_routes || [];

  if (!rows.length) {
    box.innerHTML = '<div class="empty">Not enough data yet for recommendations.</div>';
    return;
  }

  let html = '<div class="section"><div class="section-title">Best Road per Route &amp; Day</div>';
  html += '<div class="best-grid">';

  for (const g of rows) {
    const best    = g.best_route;
    const altHtml = (g.alternatives || []).map(a => `
      <div class="alt-row">
        <span class="td-road">${a.route_name || '–'}</span>
        <span>${sec2min(a.avg_duration)}</span>
      </div>`).join('');

    html += `
      <div class="best-card">
        <div class="best-card-header">
          <span class="best-card-day">${g.day_of_week || dayName(g.scheduled_day)}</span>
          ${badgeMode(g.schedule_mode)}
        </div>
        <div class="best-card-route">${g.route_label || g.route_id}</div>
        <div class="best-card-road">via ${best.route_name || '?'}</div>
        <div class="best-card-stats">
          <div class="stat-item"><label>Average</label><span>${sec2min(best.avg_duration)}</span></div>
          <div class="stat-item"><label>Best</label><span class="stat-best">${sec2min(best.min_duration)}</span></div>
          <div class="stat-item"><label>Worst</label><span class="stat-worst">${sec2min(best.max_duration)}</span></div>
          <div class="stat-item"><label>Samples</label><span>${best.sample_count}</span></div>
        </div>
        ${altHtml ? `<div class="alts"><div class="section-title" style="margin-top:10px">Alternatives</div>${altHtml}</div>` : ''}
      </div>`;
  }

  html += '</div></div>';
  box.innerHTML = html;
}

// ═══════════════════════════════════════════════════════════════════════════
// TAB: By Day
// ═══════════════════════════════════════════════════════════════════════════
async function renderByDay(box) {
  const d = await api({ action: 'by_day', ...filters() });
  const rows = d.by_day || [];

  if (!rows.length) {
    box.innerHTML = '<div class="empty">No data yet.</div>';
    return;
  }

  const routeIds  = [...new Set(rows.map(r => r.route_id))];
  const dayNums   = [1, 2, 3, 4, 5, 6, 7];
  const dayLabels = dayNums.map(dayName);

  let html = '<div class="section"><div class="section-title">Average Duration per Day (best road)</div>';
  html += '<div class="chart-wrap"><canvas id="chartByDay" height="260"></canvas></div></div>';

  html += '<div class="section"><div class="section-title">Detail</div>';
  html += '<div class="table-wrap"><table>';
  html += '<thead><tr><th>Route</th><th>Day</th><th>Road</th><th>Samples</th><th>Avg</th><th>Best</th><th>Worst</th></tr></thead><tbody>';
  for (const r of rows) {
    html += `<tr>
      <td class="td-route">${r.route_label || r.route_id}</td>
      <td>${r.day_of_week || dayName(r.scheduled_day)}</td>
      <td class="td-road">${r.route_name || '–'}</td>
      <td>${r.sample_count}</td>
      <td class="td-avg">${sec2min(r.avg_duration)}</td>
      <td class="td-min">${sec2min(r.min_duration)}</td>
      <td class="td-max">${sec2min(r.max_duration)}</td>
    </tr>`;
  }
  html += '</tbody></table></div></div>';
  box.innerHTML = html;

  const colors   = ['#5b7cf6','#38bdf8','#34d399','#fbbf24','#f87171'];
  const datasets = routeIds.map((rid, i) => {
    const label     = rows.find(r => r.route_id === rid)?.route_label || rid;
    const routeRows = rows.filter(r => r.route_id === rid);
    const data = dayNums.map(day => {
      const match = routeRows.filter(r => +r.scheduled_day === day);
      if (!match.length) return 0;
      return +(Math.min(...match.map(m => m.avg_duration)) / 60).toFixed(1);
    });
    return { label, data, color: colors[i % colors.length] };
  });

  SimpleChart.bar('chartByDay', dayLabels, datasets, { yLabel: 'minutes' });
}

// ═══════════════════════════════════════════════════════════════════════════
// TAB: Trends
// ═══════════════════════════════════════════════════════════════════════════
async function renderTrends(box) {
  const [tl, bm, brn] = await Promise.all([
    api({ action: 'timeline',      limit: 200, ...filters() }),
    api({ action: 'by_month',      ...filters() }),
    api({ action: 'by_route_name', ...filters() }),
  ]);

  const timeline = tl.timeline       || [];
  const byMonth  = bm.by_month       || [];
  const byRoad   = brn.by_route_name || [];

  let html = '';

  if (timeline.length) {
    html += '<div class="section"><div class="section-title">Timeline (primary route)</div>';
    html += '<div class="chart-wrap"><canvas id="chartTimeline" height="240"></canvas></div></div>';
  }

  if (byMonth.length) {
    html += '<div class="section"><div class="section-title">Monthly Averages</div>';
    html += '<div class="chart-wrap"><canvas id="chartMonthly" height="220"></canvas></div></div>';
  }

  if (byRoad.length) {
    html += '<div class="section"><div class="section-title">Road Comparison</div>';
    html += '<div class="table-wrap"><table>';
    html += '<thead><tr><th>Route</th><th>Road</th><th>Samples</th><th>Avg</th><th>Best</th><th>Worst</th><th>Dist (km)</th></tr></thead><tbody>';
    for (const r of byRoad) {
      html += `<tr>
        <td class="td-route">${r.route_label || r.route_id}</td>
        <td class="td-road">${r.route_name}</td>
        <td>${r.sample_count}</td>
        <td class="td-avg">${sec2min(r.avg_duration)}</td>
        <td class="td-min">${sec2min(r.min_duration)}</td>
        <td class="td-max">${sec2min(r.max_duration)}</td>
        <td>${r.avg_distance_meters ? (r.avg_distance_meters / 1000).toFixed(1) : '–'}</td>
      </tr>`;
    }
    html += '</tbody></table></div></div>';
  }

  if (!html) {
    box.innerHTML = '<div class="empty">No data yet.</div>';
    return;
  }

  box.innerHTML = html;

  // Timeline line chart — v3: all rows are primary (no route_index filter needed)
  if (timeline.length) {
    const routeIds  = [...new Set(timeline.map(r => r.route_id))];
    const allLabels = [...new Set(timeline.map(r => r.collected_at.substring(0, 10)))].sort();
    const colors    = ['#5b7cf6','#38bdf8','#34d399','#fbbf24'];

    const datasets = routeIds.map((rid, i) => {
      const label = timeline.find(r => r.route_id === rid)?.route_label || rid;
      const data  = allLabels.map(date => {
        const pts = timeline.filter(r => r.route_id === rid && r.collected_at.startsWith(date));
        if (!pts.length) return null;
        return +(pts.reduce((s, r) => s + +r.duration, 0) / pts.length / 60).toFixed(1);
      });
      return { label, data, color: colors[i % colors.length] };
    });

    SimpleChart.line('chartTimeline', allLabels, datasets, { yLabel: 'minutes' });
  }

  // Monthly bar chart
  if (byMonth.length) {
    const routeIds = [...new Set(byMonth.map(r => r.route_id))];
    const allKeys  = [...new Set(byMonth.map(r => `${r.year}-${String(r.month).padStart(2, '0')}`))].sort();
    const colors   = ['#5b7cf6','#38bdf8','#34d399','#fbbf24'];

    const datasets = routeIds.map((rid, i) => {
      const label = byMonth.find(r => r.route_id === rid)?.route_label || rid;
      const data  = allKeys.map(k => {
        const [y, m] = k.split('-');
        const row = byMonth.find(r => r.route_id === rid && +r.year === +y && +r.month === +m);
        return row ? +(row.avg_duration / 60).toFixed(1) : 0;
      });
      return { label, data, color: colors[i % colors.length] };
    });

    SimpleChart.bar('chartMonthly', allKeys, datasets, { yLabel: 'minutes' });
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// TAB: History
// ═══════════════════════════════════════════════════════════════════════════
async function renderHistory(box) {
  const d    = await api({ action: 'collections', limit: 100, ...filters() });
  const rows = d.collections || [];

  if (!rows.length) {
    box.innerHTML = '<div class="empty">No collection history yet.</div>';
    return;
  }

  let html = '<div class="section"><div class="section-title">Recent Trips (last 100)</div>';
  html += '<div class="table-wrap"><table>';
  html += '<thead><tr><th>#</th><th>Route</th><th>Day</th><th>Scheduled</th><th>Collected At</th><th>Primary Route</th><th>Status</th></tr></thead><tbody>';

  for (const r of rows) {
    const statusColor = r.api_status === 'OK' ? 'var(--green)' : 'var(--red)';
    const routeSummary = r.primary_summary && r.traffic_duration_seconds
      ? `${r.primary_summary} (${(r.traffic_duration_seconds / 60).toFixed(1)} min)`
      : (r.routes_summary || '–');

    html += `<tr>
      <td style="color:var(--muted);font-size:11px">
        ${r.id}
        ${gmapsLink(r.origin, r.destination)}
      </td>
      <td class="td-route">${r.route_label || r.route_id}</td>
      <td>${r.day_of_week || '–'}</td>
      <td>${badgeMode(r.schedule_mode)} ${r.scheduled_time || ''}</td>
      <td style="font-size:12px;color:var(--muted)">${r.collected_at || ''}</td>
      <td style="font-size:12px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${routeSummary}</td>
      <td><span style="color:${statusColor};font-weight:600;font-size:12px">${r.api_status}</span></td>
    </tr>`;
  }
  html += '</tbody></table></div></div>';
  box.innerHTML = html;
}

// ═══════════════════════════════════════════════════════════════════════════
// SimpleChart — Canvas-based charting (bar + line)
// ═══════════════════════════════════════════════════════════════════════════
const SimpleChart = (() => {
  const PAD    = { top: 20, right: 20, bottom: 60, left: 55 };
  const COLORS = ['#5b7cf6','#38bdf8','#34d399','#fbbf24','#f87171','#c084fc','#fb923c'];

  function setup(id, h) {
    const canvas = document.getElementById(id);
    if (!canvas) return null;
    const dpr = window.devicePixelRatio || 1;
    canvas.height = h * dpr;
    const w = canvas.clientWidth || canvas.offsetWidth || 600;
    canvas.width = w * dpr;
    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    return { ctx, w, h };
  }

  function drawGrid(ctx, w, h, minV, maxV, steps, yLabel) {
    ctx.strokeStyle  = '#2e3350';
    ctx.lineWidth    = 1;
    ctx.fillStyle    = '#64748b';
    ctx.font         = '11px "Segoe UI", system-ui, sans-serif';
    ctx.textAlign    = 'right';
    ctx.textBaseline = 'middle';

    for (let i = 0; i <= steps; i++) {
      const v = minV + (maxV - minV) * i / steps;
      const y = PAD.top + (h - PAD.top - PAD.bottom) * (1 - i / steps);
      ctx.beginPath(); ctx.moveTo(PAD.left, y); ctx.lineTo(w - PAD.right, y); ctx.stroke();
      ctx.fillText(v.toFixed(1), PAD.left - 6, y);
    }

    if (yLabel) {
      ctx.save();
      ctx.translate(12, h / 2);
      ctx.rotate(-Math.PI / 2);
      ctx.textAlign = 'center';
      ctx.fillText(yLabel, 0, 0);
      ctx.restore();
    }
  }

  function drawLegend(ctx, w, h, datasets) {
    const boxW = 12, gap = 6, itemW = 110;
    let x = (w - datasets.length * itemW) / 2;
    const y = h - 16;
    ctx.font         = '11px "Segoe UI", system-ui, sans-serif';
    ctx.textBaseline = 'middle';
    datasets.forEach((ds, i) => {
      const color = ds.color || COLORS[i % COLORS.length];
      ctx.fillStyle = color;
      ctx.fillRect(x, y - 5, boxW, 10);
      ctx.fillStyle = '#94a3b8';
      ctx.textAlign = 'left';
      ctx.fillText(ds.label || '', x + boxW + gap, y);
      x += itemW;
    });
  }

  function dataRange(datasets) {
    let min = Infinity, max = -Infinity;
    datasets.forEach(ds => ds.data.forEach(v => {
      if (v == null) return;
      if (v < min) min = v;
      if (v > max) max = v;
    }));
    if (min === Infinity) return { min: 0, max: 10 };
    const span = max - min || 5;
    return { min: Math.max(0, min - span * 0.1), max: max + span * 0.1 };
  }

  function scaleY(v, min, max, h) {
    return PAD.top + (h - PAD.top - PAD.bottom) * (1 - (v - min) / (max - min));
  }

  function bar(id, labels, datasets, opts = {}) {
    const s = setup(id, parseInt(document.getElementById(id)?.getAttribute('height')) || 260);
    if (!s) return;
    const { ctx, w, h } = s;

    ctx.fillStyle = '#1a1d27';
    ctx.fillRect(0, 0, w, h);

    const { min, max } = dataRange(datasets);
    drawGrid(ctx, w, h, min, max, 5, opts.yLabel || '');

    const chartW    = w - PAD.left - PAD.right;
    const groupW    = chartW / labels.length;
    const ds        = datasets.filter(d => d.data.some(v => v > 0));
    const barW      = Math.max(4, Math.min(30, groupW / (ds.length + 1) - 2));
    const totalBarW = barW * ds.length + 2 * (ds.length - 1);

    ds.forEach((d, di) => {
      const color = d.color || COLORS[di % COLORS.length];
      d.data.forEach((v, li) => {
        if (!v) return;
        const gx = PAD.left + groupW * li + groupW / 2;
        const x  = gx - totalBarW / 2 + di * (barW + 2);
        const y0 = scaleY(min, min, max, h);
        const y1 = scaleY(v,   min, max, h);
        if (y0 - y1 < 1) return;

        const r = Math.min(4, barW / 2);
        ctx.fillStyle = color;
        ctx.beginPath();
        ctx.moveTo(x + r, y1);
        ctx.lineTo(x + barW - r, y1);
        ctx.arcTo(x + barW, y1, x + barW, y1 + r, r);
        ctx.lineTo(x + barW, y0);
        ctx.lineTo(x, y0);
        ctx.lineTo(x, y1 + r);
        ctx.arcTo(x, y1, x + r, y1, r);
        ctx.closePath();
        ctx.fill();
      });
    });

    ctx.fillStyle    = '#64748b';
    ctx.font         = '11px "Segoe UI", system-ui, sans-serif';
    ctx.textAlign    = 'center';
    ctx.textBaseline = 'top';
    labels.forEach((lbl, i) => {
      const x = PAD.left + groupW * i + groupW / 2;
      ctx.fillText(lbl.length > 12 ? lbl.substring(0, 11) + '…' : lbl, x, h - PAD.bottom + 8);
    });

    if (ds.length > 1) drawLegend(ctx, w, h, ds);
  }

  function line(id, labels, datasets, opts = {}) {
    const s = setup(id, parseInt(document.getElementById(id)?.getAttribute('height')) || 260);
    if (!s) return;
    const { ctx, w, h } = s;

    ctx.fillStyle = '#1a1d27';
    ctx.fillRect(0, 0, w, h);

    const { min, max } = dataRange(datasets);
    drawGrid(ctx, w, h, min, max, 5, opts.yLabel || '');

    const chartW = w - PAD.left - PAD.right;
    const step   = labels.length > 1 ? chartW / (labels.length - 1) : chartW;

    datasets.forEach((ds, di) => {
      const color = ds.color || COLORS[di % COLORS.length];
      ctx.strokeStyle = color;
      ctx.lineWidth   = 2;
      ctx.beginPath();
      let started = false;
      ds.data.forEach((v, i) => {
        if (v == null) { started = false; return; }
        const x = PAD.left + step * i;
        const y = scaleY(v, min, max, h);
        if (!started) { ctx.moveTo(x, y); started = true; }
        else { ctx.lineTo(x, y); }
      });
      ctx.stroke();

      ds.data.forEach((v, i) => {
        if (v == null) return;
        const x = PAD.left + step * i;
        const y = scaleY(v, min, max, h);
        ctx.fillStyle = color;
        ctx.beginPath();
        ctx.arc(x, y, 3, 0, Math.PI * 2);
        ctx.fill();
      });
    });

    ctx.fillStyle    = '#64748b';
    ctx.font         = '10px "Segoe UI", system-ui, sans-serif';
    ctx.textAlign    = 'center';
    ctx.textBaseline = 'top';
    const maxLabels  = Math.floor(chartW / 60);
    const step2      = Math.max(1, Math.floor(labels.length / maxLabels));
    labels.forEach((lbl, i) => {
      if (i % step2 !== 0 && i !== labels.length - 1) return;
      const x = PAD.left + step * i;
      ctx.fillText(lbl.length > 10 ? lbl.substring(5) : lbl, x, h - PAD.bottom + 8);
    });

    if (datasets.length > 1) drawLegend(ctx, w, h, datasets);
  }

  return { bar, line };
})();

// ═══════════════════════════════════════════════════════════════════════════
// Boot
// ═══════════════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', init);

let resizeTimer;
window.addEventListener('resize', () => {
  clearTimeout(resizeTimer);
  resizeTimer = setTimeout(() => render(), 250);
});
