// settings.js — Route Tracker v3
// AJAX handlers for the settings admin UI

// ═══════════════════════════════════════════════════════════════════════════
// API helper
// ═══════════════════════════════════════════════════════════════════════════

async function apiGet(params = {}) {
  const p = new URLSearchParams(params);
  const resp = await fetch(`${API_BASE}?${p}`, { credentials: 'same-origin' });
  if (resp.status === 401) { window.location.href = 'login.php'; return null; }
  return resp.json();
}

async function apiPost(action, body = {}) {
  const resp = await fetch(`${API_BASE}?action=${action}`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (resp.status === 401) { window.location.href = 'login.php'; return null; }
  return resp.json();
}

// ═══════════════════════════════════════════════════════════════════════════
// Tab switching
// ═══════════════════════════════════════════════════════════════════════════

let currentTab = 'general';

function switchTab(tabName) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelector(`.tab[data-tab="${tabName}"]`)?.classList.add('active');
  currentTab = tabName;
  renderTab(tabName);
}

async function renderTab(tab) {
  const box  = document.getElementById('settingsContent');
  const tpl  = document.getElementById('tpl' + tab.charAt(0).toUpperCase() + tab.slice(1));
  if (!tpl) return;

  box.innerHTML = '';
  box.appendChild(tpl.content.cloneNode(true));

  switch (tab) {
    case 'general': await loadGeneral(); break;
    case 'routes':  await loadRoutes();  break;
    case 'alerts':  await loadAlerts();  break;
    case 'system':  await loadSystem();  break;
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// General tab
// ═══════════════════════════════════════════════════════════════════════════

async function loadGeneral() {
  const data = await apiGet({ action: 'get_settings' });
  if (!data) return;
  const s = data.settings || {};

  setVal('google_maps_api_key',    s.google_maps_api_key    || '');
  setVal('google_maps_language',   s.google_maps_language   || 'el');
  setVal('google_maps_region',     s.google_maps_region     || 'gr');
  setVal('app_url',                s.app_url                || '');
  setVal('timezone',               s.timezone               || 'Europe/Athens');
  setVal('window_before_minutes',  s.window_before_minutes  || '15');
  setVal('window_after_minutes',   s.window_after_minutes   || '5');
  setCheck('request_alternatives', s.request_alternatives   === '1');
}

async function saveGeneral() {
  const settings = {
    google_maps_api_key:    getVal('google_maps_api_key'),
    google_maps_language:   getVal('google_maps_language'),
    google_maps_region:     getVal('google_maps_region'),
    app_url:                getVal('app_url'),
    timezone:               getVal('timezone'),
    window_before_minutes:  getVal('window_before_minutes'),
    window_after_minutes:   getVal('window_after_minutes'),
    request_alternatives:   getCheck('request_alternatives') ? '1' : '0',
  };

  const result = await apiPost('save_setting', { settings });
  showStatus('generalStatus', result?.ok ? 'Saved!' : ('Error: ' + (result?.error || '?')), result?.ok);
}

async function testApiKey() {
  const key = getVal('google_maps_api_key');
  if (!key) { alert('Enter an API key first.'); return; }

  // Try a test collection for the first available route
  const routes = await apiGet({ action: 'route_list' });
  const rid = routes?.routes?.[0]?.id;
  if (!rid) { alert('Add a route first, then test the API key.'); return; }

  showStatus('generalStatus', 'Testing…', null);
  const result = await apiGet({ action: 'test_collection', route_id: rid });
  if (result?.status === 'OK') {
    showStatus('generalStatus', `API OK — ${result.routes?.length || 0} routes returned`, true);
  } else {
    showStatus('generalStatus', `API returned: ${result?.status || result?.error || 'error'}`, false);
  }
}

async function changePassword() {
  const current = getVal('pw_current');
  const newPw   = getVal('pw_new');
  const confirm = getVal('pw_confirm');

  if (!current || !newPw || !confirm) {
    showStatus('generalStatus', 'Fill in all password fields', false);
    return;
  }

  const result = await apiPost('change_password', { current, new_password: newPw, confirm });
  showStatus('generalStatus', result?.ok ? 'Password changed!' : ('Error: ' + (result?.error || '?')), result?.ok);

  if (result?.ok) {
    setVal('pw_current', '');
    setVal('pw_new', '');
    setVal('pw_confirm', '');
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// Routes tab
// ═══════════════════════════════════════════════════════════════════════════

async function loadRoutes() {
  const [routeData, apData] = await Promise.all([
    apiGet({ action: 'route_list', all: 1 }),
    apiGet({ action: 'alert_profiles_list' }),
  ]);
  _allAlertProfiles = apData?.alert_profiles || [];
  renderRouteList(routeData?.routes || []);
}

function renderRouteList(routes) {
  const box = document.getElementById('routesList');
  if (!box) return;

  if (!routes.length) {
    box.innerHTML = '<div class="empty">No routes yet. Click "+ Add Route" to create one.</div>';
    return;
  }

  // Build a label lookup from cached alert profiles
  const apLookup = {};
  (_allAlertProfiles || []).forEach(ap => { apLookup[ap.id] = ap.label; });

  let html = '<div class="routes-table"><table>';
  html += '<thead><tr><th>ID</th><th>Label</th><th>Origin → Destination</th><th>Alert Profiles</th><th>Advisor</th><th>Actions</th></tr></thead><tbody>';
  for (const r of routes) {
    const ids = Array.isArray(r.alert_profile_ids) ? r.alert_profile_ids : [];
    const profileBadges = ids.length
      ? ids.map(id => `<span class="ap-badge">${escHtml(apLookup[id] || id)}</span>`).join(' ')
      : '<span style="color:var(--muted)">—</span>';

    html += `<tr>
      <td><code>${escHtml(r.id)}</code></td>
      <td>${escHtml(r.label)}</td>
      <td style="font-size:12px;color:var(--muted)">${escHtml(r.origin||'')} → ${escHtml(r.destination||'')}</td>
      <td>${profileBadges}</td>
      <td>${r.advisor_enabled ? '✓' : '–'}</td>
      <td>
        <button class="btn-tiny" onclick='showRouteForm(${JSON.stringify(r).replace(/</g,'&lt;')})'>Edit</button>
        <button class="btn-tiny btn-danger" onclick="deleteRoute('${escHtml(r.id)}', '${escHtml(r.label)}')">Delete</button>
      </td>
    </tr>`;
  }
  html += '</tbody></table></div>';
  box.innerHTML = html;
}

async function showRouteForm(route) {
  const wrap = document.getElementById('routeFormWrap');
  if (!wrap) return;
  _addrCache = null;   // reset so fresh addresses are fetched on next picker open
  wrap.style.display = 'block';

  const r = route || {
    id: '', label: '', origin: '', destination: '', travel_mode: 'driving',
    schedule: [], advisor_enabled: false, advisor_start_before: 90,
    advisor_buffer_mode: 'auto', advisor_fixed_buffer: 10,
    advisor_stages: ['planning','window','reminder','urgent','last_call'],
    alert_profile_ids: [], active: true,
  };

  const stageOptions = ['planning','window','reminder','urgent','last_call'];

  const schedHtml = (r.schedule || []).map((s, i) => buildSchedRow(s, i)).join('');

  // Load alert profiles for assignment checkboxes
  if (!_allAlertProfiles) {
    const apd = await apiGet({ action: 'alert_profiles_list' });
    _allAlertProfiles = apd?.alert_profiles || [];
  }
  const assignedIds = Array.isArray(r.alert_profile_ids) ? r.alert_profile_ids : [];
  const alertProfileCheckboxes = _allAlertProfiles.length
    ? _allAlertProfiles.map(ap => `
        <label class="checkbox-label">
          <input type="checkbox" name="alert_profile_id" value="${escHtml(ap.id)}" ${assignedIds.includes(ap.id) ? 'checked' : ''}>
          ${escHtml(ap.label)}${ap.enabled ? '' : ' <span style="color:var(--muted)">(disabled)</span>'}
        </label>`).join('')
    : '<span style="color:var(--muted);font-size:13px">No alert profiles defined yet. Create one in the Alerts tab.</span>';

  const stageCheckboxes = stageOptions.map(st => `
    <label class="checkbox-label">
      <input type="checkbox" name="adv_stage" value="${st}" ${(r.advisor_stages||[]).includes(st) ? 'checked' : ''}>
      ${st}
    </label>`).join('');

  wrap.innerHTML = `
    <div class="settings-section route-form">
      <div class="section-title">${route ? 'Edit Route' : 'Add New Route'}</div>

      <div class="field-row-2">
        <div class="field-group">
          <label>Route ID (slug)</label>
          <input type="text" id="rf_id" value="${escHtml(r.id)}" placeholder="my_commute" ${route ? 'readonly' : ''}>
          <div class="field-hint">Lowercase letters, numbers, underscores only</div>
        </div>
        <div class="field-group">
          <label>Label</label>
          <input type="text" id="rf_label" value="${escHtml(r.label)}" placeholder="Home → Work">
        </div>
      </div>

      <div class="field-group">
        <label>Origin</label>
        <div class="addr-wrap">
          <input type="text" id="rf_origin" value="${escHtml(r.origin)}" placeholder="37.9838, 23.7275 or full address" autocomplete="off">
          <button type="button" class="addr-pick-btn" title="Pick from previous addresses" onclick="toggleAddrPicker('rf_origin', this)">▾</button>
          <button type="button" class="addr-map-btn" title="Preview on map" onclick="openAddrMap('rf_origin')">🗺</button>
          <div class="addr-dropdown" id="dp_rf_origin"></div>
        </div>
      </div>
      <div class="field-group">
        <label>Destination</label>
        <div class="addr-wrap">
          <input type="text" id="rf_dest" value="${escHtml(r.destination)}" placeholder="37.9838, 23.7275 or full address" autocomplete="off">
          <button type="button" class="addr-pick-btn" title="Pick from previous addresses" onclick="toggleAddrPicker('rf_dest', this)">▾</button>
          <button type="button" class="addr-map-btn" title="Preview on map" onclick="openAddrMap('rf_dest')">🗺</button>
          <div class="addr-dropdown" id="dp_rf_dest"></div>
        </div>
      </div>
      <div class="field-group">
        <label>Travel Mode</label>
        <select id="rf_mode">
          ${['driving','walking','bicycling','transit'].map(m =>
            `<option value="${m}" ${r.travel_mode===m?'selected':''}>${m}</option>`
          ).join('')}
        </select>
      </div>

      <div class="field-group">
        <label>Schedule</label>
        <div id="rf_schedule">${schedHtml}</div>
        <button class="btn-tiny" onclick="addSchedRow()" style="margin-top:6px">+ Add Schedule Entry</button>
      </div>

      <details class="advisor-details" ${r.advisor_enabled ? 'open' : ''}>
        <summary>Departure Advisor</summary>
        <div class="advisor-fields">
          <label class="checkbox-label">
            <input type="checkbox" id="rf_adv_enabled" ${r.advisor_enabled ? 'checked' : ''}>
            Enable advisor for this route
          </label>
          <div class="field-row-2">
            <div class="field-group">
              <label>Start checking X minutes before arrival</label>
              <input type="number" id="rf_adv_start" value="${r.advisor_start_before||90}" min="15" max="180">
            </div>
            <div class="field-group">
              <label>Buffer mode</label>
              <select id="rf_adv_mode">
                <option value="auto" ${r.advisor_buffer_mode==='auto'?'selected':''}>Auto (from historical variance)</option>
                <option value="fixed" ${r.advisor_buffer_mode==='fixed'?'selected':''}>Fixed</option>
              </select>
            </div>
          </div>
          <div class="field-group">
            <label>Fixed buffer (minutes, used if mode=Fixed)</label>
            <input type="number" id="rf_adv_fixed" value="${r.advisor_fixed_buffer||10}" min="0" max="60">
          </div>
          <div class="field-group">
            <label>Alert stages</label>
            <div class="checkbox-row">${stageCheckboxes}</div>
          </div>
        </div>
      </details>

      <div class="field-group">
        <label>Alert Profiles</label>
        <div class="checkbox-row">${alertProfileCheckboxes}</div>
      </div>

      <label class="checkbox-label">
        <input type="checkbox" id="rf_active" ${r.active ? 'checked' : ''}>
        Active (include in scheduled collection)
      </label>

      <div class="form-actions">
        <button class="btn-primary" onclick="saveRoute('${escHtml(r.id || '')}')">Save Route</button>
        <button class="btn-secondary" onclick="cancelRouteForm()">Cancel</button>
        <span id="routeFormStatus" class="save-status"></span>
      </div>
    </div>`;

  wrap.scrollIntoView({ behavior: 'smooth' });
}

function buildSchedRow(s, idx) {
  const days = ['Weekdays','Weekends','All','Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
  const mode = s.arrive ? 'arrive' : 'depart';
  const time = s.arrive || s.depart || '';
  return `<div class="sched-row" data-idx="${idx}">
    <select class="sched-days">
      ${days.map(d => `<option ${s.days===d?'selected':''}>${d}</option>`).join('')}
    </select>
    <select class="sched-mode">
      <option value="arrive" ${mode==='arrive'?'selected':''}>arrive by</option>
      <option value="depart" ${mode==='depart'?'selected':''}>depart at</option>
    </select>
    <input type="text" class="sched-time" value="${escHtml(time)}" placeholder="HH:MM" maxlength="5" pattern="\\d{2}:\\d{2}">
    <button class="btn-tiny btn-danger" onclick="this.closest('.sched-row').remove()">−</button>
  </div>`;
}

function addSchedRow() {
  const container = document.getElementById('rf_schedule');
  if (!container) return;
  const idx = container.querySelectorAll('.sched-row').length;
  container.insertAdjacentHTML('beforeend', buildSchedRow({days:'Weekdays'}, idx));
}

function getScheduleFromForm() {
  const rows = document.querySelectorAll('#rf_schedule .sched-row');
  const schedule = [];
  rows.forEach(row => {
    const days = row.querySelector('.sched-days')?.value;
    const mode = row.querySelector('.sched-mode')?.value;
    const time = row.querySelector('.sched-time')?.value?.trim();
    if (days && mode && time) {
      const entry = { days };
      entry[mode] = time;
      schedule.push(entry);
    }
  });
  return schedule;
}

async function saveRoute(originalId) {
  const id    = getVal('rf_id').trim();
  const label = getVal('rf_label').trim();

  if (!id || !label) {
    showStatus('routeFormStatus', 'ID and Label are required', false);
    return;
  }

  const alertProfileIds = [...document.querySelectorAll('input[name="alert_profile_id"]:checked')].map(el => el.value);
  const advisorStages   = [...document.querySelectorAll('input[name="adv_stage"]:checked')].map(el => el.value);

  const route = {
    id,
    label,
    origin:               getVal('rf_origin'),
    destination:          getVal('rf_dest'),
    travel_mode:          getVal('rf_mode'),
    schedule:             getScheduleFromForm(),
    advisor_enabled:      document.getElementById('rf_adv_enabled')?.checked ? 1 : 0,
    advisor_start_before: parseInt(getVal('rf_adv_start')) || 90,
    advisor_buffer_mode:  getVal('rf_adv_mode'),
    advisor_fixed_buffer: parseInt(getVal('rf_adv_fixed')) || 10,
    advisor_stages:       advisorStages,
    alert_profile_ids:    alertProfileIds,
    active:               document.getElementById('rf_active')?.checked ? 1 : 0,
  };

  const result = await apiPost('save_route', route);
  if (result?.ok) {
    showStatus('routeFormStatus', 'Saved!', true);
    setTimeout(() => { cancelRouteForm(); loadRoutes(); }, 800);
  } else {
    showStatus('routeFormStatus', 'Error: ' + (result?.error || '?'), false);
  }
}

function cancelRouteForm() {
  const wrap = document.getElementById('routeFormWrap');
  if (wrap) { wrap.style.display = 'none'; wrap.innerHTML = ''; }
  closeAllAddrDropdowns();
  _allAlertProfiles = null; // force reload next time form opens
}

// ─── Address picker helpers ───────────────────────────────────────────────────

let _addrCache = null;          // fetched once per form open
let _allChannelProfiles = null;  // { telegram: [], email: [], signal: [], viber: [] }
let _allAlertProfiles   = null;  // [...]

async function _fetchAddresses() {
  if (_addrCache !== null) return _addrCache;
  const data = await apiGet({ action: 'address_history' });
  _addrCache = data?.addresses || [];
  return _addrCache;
}

function closeAllAddrDropdowns() {
  document.querySelectorAll('.addr-dropdown').forEach(d => {
    d.classList.remove('open');
    d.innerHTML = '';
  });
}

async function toggleAddrPicker(inputId, btn) {
  const dropdown = document.getElementById('dp_' + inputId);
  if (!dropdown) return;

  // If already open, close it
  if (dropdown.classList.contains('open')) {
    dropdown.classList.remove('open');
    dropdown.innerHTML = '';
    return;
  }

  closeAllAddrDropdowns();

  const addresses = await _fetchAddresses();

  if (addresses.length === 0) {
    dropdown.innerHTML = '<div class="addr-empty">No previous addresses found</div>';
  } else {
    dropdown.innerHTML = addresses.map(a =>
      `<div class="addr-item" onclick="selectAddr('${inputId}', ${JSON.stringify(a)})">${escHtml(a)}</div>`
    ).join('');
  }

  dropdown.classList.add('open');

  // Close when clicking outside
  const onOutside = (e) => {
    if (!dropdown.contains(e.target) && e.target !== btn && e.target !== input) {
      dropdown.classList.remove('open');
      dropdown.innerHTML = '';
      document.removeEventListener('click', onOutside);
    }
  };
  setTimeout(() => document.addEventListener('click', onOutside), 0);
}

function selectAddr(inputId, address) {
  const input = document.getElementById(inputId);
  if (input) input.value = address;
  closeAllAddrDropdowns();
}

function openAddrMap(inputId) {
  const input = document.getElementById(inputId);
  const addr  = input?.value?.trim();
  if (!addr) { alert('Enter an address first.'); return; }
  const url = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(addr);
  window.open(url, '_blank', 'noopener,noreferrer');
}

async function deleteRoute(id, label) {
  if (!confirm(`Delete route "${label}"?\n\nThis will NOT delete historical trip data.`)) return;
  const result = await apiPost('delete_route', { id });
  if (result?.ok) {
    loadRoutes();
  } else {
    alert('Error: ' + (result?.error || 'Delete failed'));
  }
}

// ═══════════════════════════════════════════════════════════════════════════
// Alerts tab — profile-based system
// ═══════════════════════════════════════════════════════════════════════════

async function loadAlerts() {
  // Load thresholds
  const data = await apiGet({ action: 'get_settings' });
  if (data) {
    const s = data.settings || {};
    setVal('alert_traffic_threshold', s.alert_traffic_threshold || '30');
    setVal('alert_min_samples',       s.alert_min_samples       || '5');
    setVal('alert_max_per_day',       s.alert_max_per_day       || '3');
  }

  // Load channel profiles
  const cpData = await apiGet({ action: 'channel_profiles_list' });
  _allChannelProfiles = cpData?.channel_profiles || { telegram: [], email: [], signal: [], viber: [] };
  ['telegram','email','signal','viber'].forEach(t => renderChannelProfileTable(t, _allChannelProfiles[t] || []));

  // Load alert profiles
  const apData = await apiGet({ action: 'alert_profiles_list' });
  _allAlertProfiles = apData?.alert_profiles || [];
  renderAlertProfileTable(_allAlertProfiles);
}

async function saveAlertThresholds() {
  const settings = {
    alert_traffic_threshold: getVal('alert_traffic_threshold'),
    alert_min_samples:       getVal('alert_min_samples'),
    alert_max_per_day:       getVal('alert_max_per_day'),
  };
  const result = await apiPost('save_setting', { settings });
  showStatus('alertStatus', result?.ok ? 'Saved!' : ('Error: ' + (result?.error || '?')), result?.ok);
}

// ─── Channel Profiles ─────────────────────────────────────────────────────

function renderChannelProfileTable(type, profiles) {
  const box = document.getElementById(`cpTable-${type}`);
  if (!box) return;
  if (!profiles.length) {
    box.innerHTML = '<div class="cp-empty">No profiles yet.</div>';
    return;
  }
  let html = '<table class="cp-table"><thead><tr><th>Label</th><th>Enabled</th><th>Actions</th></tr></thead><tbody>';
  for (const p of profiles) {
    html += `<tr>
      <td>${escHtml(p.label)}</td>
      <td>${p.enabled ? 'Yes' : 'No'}</td>
      <td>
        <button class="btn-tiny" onclick="editChannelProfile('${type}', '${escHtml(p.id)}')">Edit</button>
        <button class="btn-tiny" onclick="testChannelProfile('${type}', '${escHtml(p.id)}', '${escHtml(p.label)}')">Test</button>
        <button class="btn-tiny btn-danger" onclick="deleteChannelProfile('${type}', '${escHtml(p.id)}', '${escHtml(p.label)}')">Delete</button>
      </td>
    </tr>`;
  }
  html += '</tbody></table>';
  box.innerHTML = html;
}

function addChannelProfile(type) {
  showChannelProfileForm(type, null);
}

async function editChannelProfile(type, id) {
  // Find profile from cache
  const profile = (_allChannelProfiles?.[type] || []).find(p => p.id === id);
  showChannelProfileForm(type, profile || null);
}

function showChannelProfileForm(type, profile) {
  // Hide all other forms
  ['telegram','email','signal','viber'].forEach(t => {
    if (t !== type) {
      const f = document.getElementById(`cpForm-${t}`);
      if (f) { f.style.display = 'none'; f.innerHTML = ''; }
    }
  });

  const box = document.getElementById(`cpForm-${type}`);
  if (!box) return;
  box.style.display = 'block';

  const p = profile || { id: '', label: '', enabled: true };
  const originalId = profile?.id || '';

  let typeFields = '';
  if (type === 'telegram') {
    typeFields = `
      <div class="field-group">
        <label>Bot Token</label>
        <input type="password" id="cpf_bot_token" value="${escHtml(p.bot_token || '')}" placeholder="123456789:ABCdef...">
      </div>
      <div class="field-group">
        <label>Chat IDs (comma-separated)</label>
        <input type="text" id="cpf_chat_ids" value="${escHtml(p.chat_ids || '')}" placeholder="-1001234567890, 987654321">
      </div>`;
  } else if (type === 'email') {
    typeFields = `
      <div class="field-row-2">
        <div class="field-group">
          <label>SMTP Host</label>
          <input type="text" id="cpf_smtp_host" value="${escHtml(p.smtp_host || '')}" placeholder="smtp.gmail.com">
        </div>
        <div class="field-group">
          <label>Port</label>
          <input type="number" id="cpf_smtp_port" value="${p.smtp_port || 587}" min="1" max="65535">
        </div>
      </div>
      <div class="field-group">
        <label>Encryption</label>
        <select id="cpf_smtp_encryption">
          <option value="tls" ${p.smtp_encryption==='tls'?'selected':''}>TLS (STARTTLS)</option>
          <option value="ssl" ${p.smtp_encryption==='ssl'?'selected':''}>SSL</option>
          <option value="none" ${p.smtp_encryption==='none'?'selected':''}>None</option>
        </select>
      </div>
      <div class="field-row-2">
        <div class="field-group">
          <label>SMTP User</label>
          <input type="text" id="cpf_smtp_user" value="${escHtml(p.smtp_user || '')}" placeholder="user@example.com">
        </div>
        <div class="field-group">
          <label>SMTP Password</label>
          <input type="password" id="cpf_smtp_pass" value="${escHtml(p.smtp_pass || '')}">
        </div>
      </div>
      <div class="field-row-2">
        <div class="field-group">
          <label>From Address</label>
          <input type="text" id="cpf_from_address" value="${escHtml(p.from_address || '')}" placeholder="noreply@example.com">
        </div>
        <div class="field-group">
          <label>From Name</label>
          <input type="text" id="cpf_from_name" value="${escHtml(p.from_name || 'Route Tracker')}">
        </div>
      </div>
      <div class="field-group">
        <label>Recipients (comma-separated)</label>
        <input type="text" id="cpf_recipients" value="${escHtml(p.recipients || '')}" placeholder="you@example.com, other@example.com">
      </div>`;
  } else if (type === 'signal') {
    typeFields = `
      <div class="field-group">
        <label>Signal CLI / API URL</label>
        <input type="text" id="cpf_api_url" value="${escHtml(p.api_url || '')}" placeholder="http://localhost:8080">
      </div>
      <div class="field-group">
        <label>Sender Number</label>
        <input type="text" id="cpf_sender_number" value="${escHtml(p.sender_number || '')}" placeholder="+30xxxxxxxxxx">
      </div>
      <div class="field-group">
        <label>Recipient Numbers (comma-separated)</label>
        <input type="text" id="cpf_recipient_numbers" value="${escHtml(p.recipient_numbers || '')}" placeholder="+30xxxxxxxxxx, +30yyyyyyyyyy">
      </div>`;
  } else if (type === 'viber') {
    typeFields = `
      <div class="field-group">
        <label>Auth Token</label>
        <input type="password" id="cpf_auth_token" value="${escHtml(p.auth_token || '')}" placeholder="Viber bot auth token">
      </div>
      <div class="field-group">
        <label>Receiver IDs (comma-separated)</label>
        <input type="text" id="cpf_receiver_ids" value="${escHtml(p.receiver_ids || '')}" placeholder="user_viber_id_1, user_viber_id_2">
      </div>`;
  }

  box.innerHTML = `
    <div class="cp-form">
      <div class="field-row-2">
        <div class="field-group">
          <label>Profile ID (slug)</label>
          <input type="text" id="cpf_id" value="${escHtml(p.id)}" placeholder="my_telegram" ${profile ? 'readonly' : ''}>
        </div>
        <div class="field-group">
          <label>Label</label>
          <input type="text" id="cpf_label" value="${escHtml(p.label)}" placeholder="Personal Telegram">
        </div>
      </div>
      ${typeFields}
      <label class="checkbox-label" style="margin-top:8px">
        <input type="checkbox" id="cpf_enabled" ${p.enabled ? 'checked' : ''}> Enabled
      </label>
      <div class="form-actions" style="margin-top:12px">
        <button class="btn-primary" onclick="saveChannelProfile('${type}', '${escHtml(originalId)}')">Save</button>
        <button class="btn-secondary" onclick="cancelChannelProfileForm('${type}')">Cancel</button>
        <span id="cpFormStatus-${type}" class="save-status"></span>
      </div>
    </div>`;

  box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function cancelChannelProfileForm(type) {
  const box = document.getElementById(`cpForm-${type}`);
  if (box) { box.style.display = 'none'; box.innerHTML = ''; }
}

async function saveChannelProfile(type, originalId) {
  const id    = getVal('cpf_id').trim();
  const label = getVal('cpf_label').trim();
  if (!id || !label) {
    showStatus(`cpFormStatus-${type}`, 'ID and Label are required', false);
    return;
  }

  const data = { id, label, type, original_id: originalId, enabled: document.getElementById('cpf_enabled')?.checked ? 1 : 0 };

  if (type === 'telegram') {
    data.bot_token = getVal('cpf_bot_token');
    data.chat_ids  = getVal('cpf_chat_ids');
  } else if (type === 'email') {
    data.smtp_host       = getVal('cpf_smtp_host');
    data.smtp_port       = parseInt(getVal('cpf_smtp_port')) || 587;
    data.smtp_encryption = getVal('cpf_smtp_encryption');
    data.smtp_user       = getVal('cpf_smtp_user');
    data.smtp_pass       = getVal('cpf_smtp_pass');
    data.from_address    = getVal('cpf_from_address');
    data.from_name       = getVal('cpf_from_name');
    data.recipients      = getVal('cpf_recipients');
  } else if (type === 'signal') {
    data.api_url          = getVal('cpf_api_url');
    data.sender_number    = getVal('cpf_sender_number');
    data.recipient_numbers = getVal('cpf_recipient_numbers');
  } else if (type === 'viber') {
    data.auth_token   = getVal('cpf_auth_token');
    data.receiver_ids = getVal('cpf_receiver_ids');
  }

  const result = await apiPost('channel_profiles_save', data);
  if (result?.ok) {
    showStatus(`cpFormStatus-${type}`, 'Saved!', true);
    _allChannelProfiles = null; // invalidate cache
    _allAlertProfiles   = null;
    setTimeout(async () => {
      cancelChannelProfileForm(type);
      const cpd = await apiGet({ action: 'channel_profiles_list' });
      _allChannelProfiles = cpd?.channel_profiles || { telegram: [], email: [], signal: [], viber: [] };
      renderChannelProfileTable(type, _allChannelProfiles[type] || []);
    }, 600);
  } else {
    showStatus(`cpFormStatus-${type}`, 'Error: ' + (result?.error || '?'), false);
  }
}

async function deleteChannelProfile(type, id, label) {
  if (!confirm(`Delete "${label}" (${type})?\nIt must not be used in any alert profile.`)) return;
  const result = await apiPost('channel_profiles_delete', { type, id });
  if (result?.ok) {
    _allChannelProfiles = null;
    const cpd = await apiGet({ action: 'channel_profiles_list' });
    _allChannelProfiles = cpd?.channel_profiles || { telegram: [], email: [], signal: [], viber: [] };
    renderChannelProfileTable(type, _allChannelProfiles[type] || []);
  } else {
    alert('Error: ' + (result?.error || 'Delete failed'));
  }
}

async function testChannelProfile(type, id, label) {
  const btn = event.target;
  btn.disabled = true;
  btn.textContent = 'Sending…';
  const result = await apiPost('channel_profiles_test', { type, id });
  btn.disabled = false;
  btn.textContent = 'Test';
  alert(result?.ok ? `Sent to "${label}" successfully.` : `Failed: ${result?.error || result?.message || 'unknown error'}`);
}

// ─── Alert Profiles ───────────────────────────────────────────────────────

function renderAlertProfileTable(profiles) {
  const box = document.getElementById('alertProfilesTable');
  if (!box) return;
  if (!profiles.length) {
    box.innerHTML = '<div class="cp-empty">No alert profiles yet. Click "+ Add Profile" to create one.</div>';
    return;
  }
  let html = '<table class="cp-table"><thead><tr><th>Label</th><th>Channels</th><th>Enabled</th><th>Actions</th></tr></thead><tbody>';
  for (const ap of profiles) {
    const channels = (ap.channels || []).map(b => `${b.type}:${b.profile_id}`).join(', ') || '—';
    html += `<tr>
      <td>${escHtml(ap.label)}</td>
      <td style="font-size:12px;color:var(--muted)">${escHtml(channels)}</td>
      <td>${ap.enabled ? 'Yes' : 'No'}</td>
      <td>
        <button class="btn-tiny" onclick="editAlertProfile('${escHtml(ap.id)}')">Edit</button>
        <button class="btn-tiny" onclick="testAlertProfile('${escHtml(ap.id)}', '${escHtml(ap.label)}')">Test</button>
        <button class="btn-tiny btn-danger" onclick="deleteAlertProfile('${escHtml(ap.id)}', '${escHtml(ap.label)}')">Delete</button>
      </td>
    </tr>`;
  }
  html += '</tbody></table>';
  box.innerHTML = html;
}

function addAlertProfile() {
  showAlertProfileForm(null);
}

async function editAlertProfile(id) {
  const profile = (_allAlertProfiles || []).find(ap => ap.id === id);
  showAlertProfileForm(profile || null);
}

function showAlertProfileForm(profile) {
  const box = document.getElementById('alertProfileForm');
  if (!box) return;
  box.style.display = 'block';

  const ap = profile || { id: '', label: '', channels: [], enabled: true };
  const originalId = profile?.id || '';

  // Build available channel profile options grouped by type
  const cpGroups = _allChannelProfiles || { telegram: [], email: [], signal: [], viber: [] };

  const bindingsHtml = (ap.channels || []).map((b, i) => buildBindingRow(cpGroups, i, b.type, b.profile_id)).join('');

  box.innerHTML = `
    <div class="cp-form">
      <div class="field-row-2">
        <div class="field-group">
          <label>Profile ID (slug)</label>
          <input type="text" id="apf_id" value="${escHtml(ap.id)}" placeholder="my_alerts" ${profile ? 'readonly' : ''}>
        </div>
        <div class="field-group">
          <label>Label</label>
          <input type="text" id="apf_label" value="${escHtml(ap.label)}" placeholder="Work Alerts">
        </div>
      </div>
      <div class="field-group">
        <label>Channel Bindings</label>
        <div id="apf_bindings">${bindingsHtml}</div>
        <button class="btn-tiny" onclick="addBindingRow()" style="margin-top:6px">+ Add Channel</button>
      </div>
      <label class="checkbox-label" style="margin-top:8px">
        <input type="checkbox" id="apf_enabled" ${ap.enabled ? 'checked' : ''}> Enabled
      </label>
      <div class="form-actions" style="margin-top:12px">
        <button class="btn-primary" onclick="saveAlertProfile('${escHtml(originalId)}')">Save</button>
        <button class="btn-secondary" onclick="cancelAlertProfileForm()">Cancel</button>
        <span id="apfStatus" class="save-status"></span>
      </div>
    </div>`;

  box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function buildBindingRow(cpGroups, idx, selectedType, selectedProfileId) {
  const types = ['telegram','email','signal','viber'];
  const typeOpts = types.map(t => `<option value="${t}" ${t === selectedType ? 'selected' : ''}>${t}</option>`).join('');

  const profileOpts = buildProfileOpts(cpGroups, selectedType, selectedProfileId);

  return `<div class="binding-row" data-idx="${idx}">
    <select class="binding-type" onchange="onBindingTypeChange(this)">
      ${typeOpts}
    </select>
    <select class="binding-profile">${profileOpts}</select>
    <button class="btn-tiny btn-danger" onclick="this.closest('.binding-row').remove()">−</button>
  </div>`;
}

function buildProfileOpts(cpGroups, type, selectedId) {
  const profiles = (cpGroups && cpGroups[type]) || [];
  if (!profiles.length) return '<option value="">(no profiles)</option>';
  return profiles.map(p => `<option value="${escHtml(p.id)}" ${p.id === selectedId ? 'selected' : ''}>${escHtml(p.label)}</option>`).join('');
}

function onBindingTypeChange(typeSelect) {
  const row = typeSelect.closest('.binding-row');
  const profileSel = row?.querySelector('.binding-profile');
  if (!profileSel) return;
  const type = typeSelect.value;
  profileSel.innerHTML = buildProfileOpts(_allChannelProfiles || {}, type, '');
}

function addBindingRow() {
  const container = document.getElementById('apf_bindings');
  if (!container) return;
  const idx = container.querySelectorAll('.binding-row').length;
  container.insertAdjacentHTML('beforeend', buildBindingRow(_allChannelProfiles || {}, idx, 'telegram', ''));
}

function getBindingsFromForm() {
  const rows = document.querySelectorAll('#apf_bindings .binding-row');
  const bindings = [];
  rows.forEach(row => {
    const type      = row.querySelector('.binding-type')?.value;
    const profileId = row.querySelector('.binding-profile')?.value;
    if (type && profileId) bindings.push({ type, profile_id: profileId });
  });
  return bindings;
}

function cancelAlertProfileForm() {
  const box = document.getElementById('alertProfileForm');
  if (box) { box.style.display = 'none'; box.innerHTML = ''; }
}

async function saveAlertProfile(originalId) {
  const id    = getVal('apf_id').trim();
  const label = getVal('apf_label').trim();
  if (!id || !label) {
    showStatus('apfStatus', 'ID and Label are required', false);
    return;
  }

  const data = {
    id,
    label,
    original_id: originalId,
    channels:    getBindingsFromForm(),
    enabled:     document.getElementById('apf_enabled')?.checked ? 1 : 0,
  };

  const result = await apiPost('alert_profiles_save', data);
  if (result?.ok) {
    showStatus('apfStatus', 'Saved!', true);
    _allAlertProfiles = null;
    setTimeout(async () => {
      cancelAlertProfileForm();
      const apd = await apiGet({ action: 'alert_profiles_list' });
      _allAlertProfiles = apd?.alert_profiles || [];
      renderAlertProfileTable(_allAlertProfiles);
    }, 600);
  } else {
    showStatus('apfStatus', 'Error: ' + (result?.error || '?'), false);
  }
}

async function deleteAlertProfile(id, label) {
  if (!confirm(`Delete alert profile "${label}"?\nIt must not be assigned to any route.`)) return;
  const result = await apiPost('alert_profiles_delete', { id });
  if (result?.ok) {
    _allAlertProfiles = null;
    const apd = await apiGet({ action: 'alert_profiles_list' });
    _allAlertProfiles = apd?.alert_profiles || [];
    renderAlertProfileTable(_allAlertProfiles);
  } else {
    alert('Error: ' + (result?.error || 'Delete failed'));
  }
}

async function testAlertProfile(id, label) {
  const btn = event.target;
  btn.disabled = true;
  btn.textContent = 'Sending…';
  const result = await apiPost('alert_profiles_test', { id });
  btn.disabled = false;
  btn.textContent = 'Test';
  alert(result?.ok ? `Test sent via "${label}" successfully.` : `Failed: ${result?.error || result?.message || 'unknown error'}`);
}

// ═══════════════════════════════════════════════════════════════════════════
// System tab
// ═══════════════════════════════════════════════════════════════════════════

async function loadSystem() {
  // Load route list for dropdown
  const data = await apiGet({ action: 'route_list' });
  const sel  = document.getElementById('testRouteId');
  if (sel && data?.routes) {
    data.routes.forEach(r => {
      const opt = document.createElement('option');
      opt.value       = r.id;
      opt.textContent = r.label;
      sel.appendChild(opt);
    });
  }

  // Load DB stats
  await loadDbStats();

  // Load collector log by default
  await loadLog('collector');
}

async function loadDbStats() {
  const data = await apiGet({ action: 'db_stats' });
  const box  = document.getElementById('dbStats');
  if (!box || !data) return;

  const size = data.db_size_bytes > 1048576
    ? (data.db_size_bytes / 1048576).toFixed(1) + ' MB'
    : (data.db_size_bytes / 1024).toFixed(0) + ' KB';

  box.innerHTML = `
    <div class="stat-chip"><label>Trips</label><span>${data.trip_count.toLocaleString()}</span></div>
    <div class="stat-chip"><label>Active Routes</label><span>${data.route_count}</span></div>
    <div class="stat-chip"><label>First Trip</label><span>${data.first_trip ? data.first_trip.substring(0,10) : '–'}</span></div>
    <div class="stat-chip"><label>Last Trip</label><span>${data.last_trip ? data.last_trip.substring(0,10) : '–'}</span></div>
    <div class="stat-chip"><label>DB Size</label><span>${size}</span></div>`;
}

async function runTestCollection() {
  const rid = document.getElementById('testRouteId')?.value;
  if (!rid) { alert('Select a route first.'); return; }

  const box = document.getElementById('testResult');
  if (box) { box.style.display = 'block'; box.textContent = 'Running…'; }

  const result = await apiGet({ action: 'test_collection', route_id: rid });
  if (!box) return;

  if (!result) { box.textContent = 'Error: no response'; return; }

  let txt = `Route: ${result.label}\nStatus: ${result.status}\n`;
  if (result.error) txt += `Error: ${result.error}\n`;
  (result.routes || []).forEach((r, i) => {
    txt += `\n${i === 0 ? '★ PRIMARY' : '  ALT ' + i}: ${r.summary}\n`;
    txt += `  Duration: ${r.duration}  Traffic: ${r.traffic || 'n/a'}  Distance: ${r.distance}\n`;
  });
  box.textContent = txt;
}

async function runAdvisor() {
  const result = await apiGet({ action: 'run_advisor' });
  alert(result?.ran_for?.length
    ? `Advisor ran for: ${result.ran_for.join(', ')}`
    : 'Advisor ran (no advisor-enabled routes in window)');
}

function exportTrips() {
  window.location.href = `${API_BASE}?action=export_trips`;
}

function exportConfig() {
  window.location.href = `${API_BASE}?action=export_config`;
}

async function importConfig() {
  const file = document.getElementById('configBackupFile')?.files?.[0];
  if (!file) { alert('Select a backup JSON file first.'); return; }

  if (!confirm('This will overwrite ALL current settings and routes with the backup. Continue?')) return;

  const statusEl = document.getElementById('importStatus');
  if (statusEl) { statusEl.textContent = 'Restoring…'; statusEl.className = 'save-status'; }

  let backup;
  try {
    backup = JSON.parse(await file.text());
  } catch (e) {
    if (statusEl) { statusEl.textContent = 'Invalid file: ' + e.message; statusEl.className = 'save-status error'; }
    return;
  }

  const result = await apiPost('import_config', backup);

  if (result?.ok) {
    if (statusEl) {
      statusEl.textContent = `Restored: ${result.settings_updated} settings, ${result.routes_imported} routes.`;
      statusEl.className = 'save-status ok';
    }
    setTimeout(() => location.reload(), 1500);
  } else {
    if (statusEl) {
      statusEl.textContent = result?.error || 'Restore failed.';
      statusEl.className = 'save-status error';
    }
  }
}

async function loadLog(type, btn) {
  // Update active tab button
  document.querySelectorAll('.log-tab-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');

  const data = await apiGet({ action: 'get_logs', type });
  const box  = document.getElementById('logBox');
  if (!box) return;
  box.textContent = data?.log?.join('\n') || '(no log entries)';
  box.scrollTop   = box.scrollHeight;
}

// ═══════════════════════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════════════════════

function getVal(id)         { return document.getElementById(id)?.value ?? ''; }
function setVal(id, v)      { const el = document.getElementById(id); if (el) el.value = v; }
function getCheck(id)       { return document.getElementById(id)?.checked ?? false; }
function setCheck(id, v)    { const el = document.getElementById(id); if (el) el.checked = v; }

function showStatus(id, msg, ok) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = msg;
  el.className   = 'save-status ' + (ok === true ? 'ok' : ok === false ? 'err' : '');
  if (ok === true) {
    setTimeout(() => { el.textContent = ''; el.className = 'save-status'; }, 3000);
  }
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ═══════════════════════════════════════════════════════════════════════════
// Init
// ═══════════════════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.tab').forEach(t => {
    t.addEventListener('click', () => switchTab(t.dataset.tab));
  });
  renderTab('general');
});
