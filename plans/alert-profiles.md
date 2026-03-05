# Alert Profiles — v3 Enhancement Plan

> Supersedes: `telegram-alert-profiles.md` (too narrow; extended to all channels)

---

## Problem Statement

v3 currently supports four alert channels (Telegram, Email, Viber, Signal), each
with a single global configuration. Every route that includes `"telegram"` in its
`alert_channels` sends to the same bot and the same set of chat IDs. There is no
way to send different routes to different recipients without modifying the global
config.

The goal is a **two-tier profile system**:

1. **Channel profiles** — Named, reusable credentials for one specific channel
   type (e.g. a Telegram bot, an SMTP account, a Signal sender).
2. **Alert profiles** — Named bundles that reference one or more channel profiles.
   Routes subscribe to alert profiles rather than raw channel names.

---

## Conceptual Model

```
Alert Profile "family"
├── telegram → Channel Profile "family_bot"  (bot_token + family group chat ID)
└── email    → Channel Profile "personal"    (Gmail SMTP + dad@example.com)

Alert Profile "work"
└── telegram → Channel Profile "work_bot"    (different bot + work group chat ID)

Alert Profile "ops"
├── email    → Channel Profile "sysadmin"    (Postfix + ops@company.com)
└── signal   → Channel Profile "oncall"      (signal-cli + on-call number)

Route "Son → School"   → alert_profiles: ["family"]
Route "Vangelis → Work" → alert_profiles: ["family", "work"]
Route "Server Monitor"  → alert_profiles: ["ops"]
```

A route can belong to multiple alert profiles; each profile dispatches
independently. A profile can combine any mix of channel profiles.

---

## Database Changes (`schema.php`)

### New Tables

#### `telegram_profiles`
```
id          TEXT PRIMARY KEY   -- slug, e.g. "family_bot"
label       TEXT NOT NULL      -- display name, e.g. "Family Bot"
bot_token   TEXT NOT NULL
chat_ids    TEXT NOT NULL      -- comma-separated
enabled     INTEGER DEFAULT 1
created_at  TEXT
updated_at  TEXT
```

#### `email_profiles`
```
id              TEXT PRIMARY KEY
label           TEXT NOT NULL
smtp_host       TEXT NOT NULL
smtp_port       INTEGER DEFAULT 587
smtp_encryption TEXT DEFAULT 'tls'   -- 'tls' | 'ssl' | 'none'
smtp_user       TEXT NOT NULL
smtp_pass       TEXT NOT NULL
from_address    TEXT NOT NULL
from_name       TEXT DEFAULT 'Route Tracker'
recipients      TEXT NOT NULL        -- comma-separated
enabled         INTEGER DEFAULT 1
created_at      TEXT
updated_at      TEXT
```

#### `signal_profiles`
```
id                 TEXT PRIMARY KEY
label              TEXT NOT NULL
api_url            TEXT NOT NULL    -- signal-cli REST API base URL
sender_number      TEXT NOT NULL    -- E.164 format
recipient_numbers  TEXT NOT NULL    -- comma-separated, E.164
enabled            INTEGER DEFAULT 1
created_at         TEXT
updated_at         TEXT
```

#### `viber_profiles`
```
id           TEXT PRIMARY KEY
label        TEXT NOT NULL
auth_token   TEXT NOT NULL
receiver_ids TEXT NOT NULL    -- comma-separated
enabled      INTEGER DEFAULT 1
created_at   TEXT
updated_at   TEXT
```

#### `alert_profiles`
```
id         TEXT PRIMARY KEY   -- slug, e.g. "family", "work"
label      TEXT NOT NULL      -- display name
channels   TEXT NOT NULL      -- JSON: [{"type":"telegram","profile_id":"family_bot"}, ...]
enabled    INTEGER DEFAULT 1
created_at TEXT
updated_at TEXT
```

The `channels` column holds a JSON array of channel-profile bindings:
```json
[
  { "type": "telegram", "profile_id": "family_bot" },
  { "type": "email",    "profile_id": "personal"   },
  { "type": "signal",   "profile_id": "oncall"      }
]
```

### Changes to Existing Tables

#### `routes` table
Add column:
```
alert_profile_ids  TEXT  DEFAULT '[]'   -- JSON array of alert profile id strings
```

The existing `alert_channels` column is **kept** for backwards compatibility.
When a route has a non-empty `alert_profile_ids`, the profile system takes
precedence; otherwise the legacy flat `alert_channels` path is used.

### Migration / Backwards Compatibility

- All new tables are `CREATE TABLE IF NOT EXISTS` — safe to run `--init` on
  existing databases.
- Existing routes using flat `alert_channels` continue to work unchanged through
  the legacy code path in `AlertManager`.
- The global `telegram_*`, `email_*`, `signal_*`, `viber_*` settings keys in
  the `settings` table are **not removed** — they remain as the implicit
  "default" configuration used by the legacy path.

---

## `Config.php` Changes

### Channel Profile Lookups

New public methods (one per channel type):

```
getTelegramProfile(id)  → array|null
getEmailProfile(id)     → array|null
getSignalProfile(id)    → array|null
getViberProfile(id)     → array|null
```

Each returns the full config array for that profile (normalised to the same
shape `AlertManager` already expects from `getAlertConfig()`), or `null` if the
id is not found or the profile is disabled.

### Alert Profile Lookups

```
getAlertProfile(id)           → array|null   -- single profile with resolved channels
getAllAlertProfiles()          → array         -- all profiles ordered by label
getRouteAlertProfiles(route)  → array         -- profiles assigned to this route
```

`getAlertProfile()` returns the raw DB row. Resolution of each binding's
channel config is done inside `AlertManager` (keeps Config as a data layer).

### CRUD Helpers (called from `api.php`)

```
saveChannelProfile(type, data)    -- type in {telegram,email,signal,viber}
deleteChannelProfile(type, id)
saveAlertProfile(data)
deleteAlertProfile(id)
```

### Extended `getAlertConfig()` (legacy support)

`getAlertConfig(channel)` is unchanged for the four plain channel names
(`telegram`, `email`, `signal`, `viber`). No new magic string handling is
needed because the profile system bypasses `getAlertConfig()` entirely.

### Extended `isAlertEnabled()` / `getRouteAlertChannels()`

These two methods are unchanged — they continue to serve the legacy flat path.
A new parallel method is added:

```
getRouteAlertProfileIds(route)  → string[]   -- from alert_profile_ids JSON
```

---

## `AlertManager.php` Changes

### Dispatch Flow (extended, not replaced)

`evaluateAndAlert()` gains a new branch at the top:

```
profiles = config->getRouteAlertProfiles(route)

if profiles is non-empty:
    for each profile:
        dispatchProfile(profile, subject, body, route)
else:
    // legacy path — unchanged
    channels = config->getRouteAlertChannels(route)
    dispatch(channels, subject, body, route)
```

This means routes using the old flat `alert_channels` continue to work with
zero changes. New routes use profiles exclusively.

### New `dispatchProfile(profile, subject, body, route)`

Iterates the `channels` array of a profile binding:

```
for each binding in profile.channels:
    cfg = config->get<Type>Profile(binding.profile_id)
    if cfg is null or disabled → log and skip
    switch binding.type:
        telegram → sendTelegramCfg(cfg, body)
        email    → sendEmailCfg(cfg, subject, body)
        signal   → sendSignalCfg(cfg, body)
        viber    → sendViberCfg(cfg, body)
    log result
```

### Refactored Send Methods

Each channel's send logic is split into two layers:

1. **`sendTelegram(body)`** — existing method, uses global settings, unchanged
   (legacy path).
2. **`sendTelegramCfg(cfg, body)`** — new method, accepts a pre-resolved config
   array; contains the actual HTTP logic (currently duplicated in
   `sendTelegram()`). The legacy method becomes a thin wrapper calling this.

Same pattern for `sendEmail`/`sendEmailCfg`, `sendSignal`/`sendSignalCfg`,
`sendViber`/`sendViberCfg`.

### `sendTest()` and `sendTestChannel()`

`sendTest()` — when a route uses profiles, test messages are dispatched via
`dispatchProfile()` for each assigned profile.

`sendTestChannel(channel)` — unchanged for legacy channel names.

New method:

```
sendTestAlertProfile(profileId)   -- sends test to all channel profiles in the bundle
sendTestChannelProfile(type, profileId)  -- sends test via one specific channel profile
```

---

## `api.php` Changes

New action groups (all require active session):

### Channel Profile CRUD

| Action | Method | Description |
|---|---|---|
| `channel_profiles_list` | GET | All profiles for all types, grouped by type |
| `channel_profiles_save` | POST | Upsert a channel profile (`type` + `data`) |
| `channel_profiles_delete` | POST | Delete by `type` + `id` |
| `channel_profiles_test` | POST | Send a test message via `type` + `id` |

`channel_profiles_save` payload example:
```json
{
  "type": "telegram",
  "id": "family_bot",
  "label": "Family Bot",
  "bot_token": "123456:ABC…",
  "chat_ids": "-100123456789",
  "enabled": true
}
```

### Alert Profile CRUD

| Action | Method | Description |
|---|---|---|
| `alert_profiles_list` | GET | All alert profiles with their channel bindings |
| `alert_profiles_save` | POST | Upsert an alert profile |
| `alert_profiles_delete` | POST | Delete by `id` |
| `alert_profiles_test` | POST | Send a test via all channels in the profile |

`alert_profiles_save` payload:
```json
{
  "id": "family",
  "label": "Family",
  "channels": [
    { "type": "telegram", "profile_id": "family_bot" },
    { "type": "email",    "profile_id": "personal"   }
  ],
  "enabled": true
}
```

**Validation rules (both profile types):**
- `id` must match `/^[a-z0-9_-]+$/`
- `id` must not be `"default"` (reserved)
- `label` must not be empty
- Channel-profile-specific required fields validated per type
- Cannot delete a channel profile that is referenced by an alert profile
- Cannot delete an alert profile that is referenced by a route

---

## Settings UI Changes (`settings.php` + `settings.js`)

### New Tab or Section Structure

The existing Alerts tab is reorganised into three levels:

```
Alerts
├── Global Settings  (thresholds, rate limits — unchanged)
├── Channel Profiles
│   ├── Telegram profiles  [table + add/edit/delete/test]
│   ├── Email profiles     [table + add/edit/delete/test]
│   ├── Signal profiles    [table + add/edit/delete/test]
│   └── Viber profiles     [table + add/edit/delete/test]
└── Alert Profiles     [table + composer + add/edit/delete/test]
```

The old global per-channel config blocks (single bot token field, etc.) are
kept under a **"Legacy / Default"** collapsible inside each channel section,
clearly labelled as backwards-compatible defaults.

### Channel Profile UI (one per channel type)

Each channel type section shows:
- A table: `ID | Label | Target(s) | Enabled | Actions`
- **Actions per row:** Edit (inline form expand) / Delete (with guard if in
  use) / Test (fires `channel_profiles_test`, shows toast)
- **"+ Add profile"** button opens the inline form
- Form fields vary by channel type (bot token for Telegram, SMTP fields for
  Email, etc.)

### Alert Profile UI

- A table: `ID | Label | Channels | Enabled | Actions`
- **Channels column** shows badges: `TG: family_bot`, `Email: personal`
- **Edit form** includes a channel binding composer:
  - A "+" button to add a binding row
  - Each row: channel type dropdown → profile dropdown (populated from saved
    channel profiles of that type) → remove button
- **Test button** per row fires `alert_profiles_test`

### Route Form Update

The route editor's alert assignment changes from:

```
☑ telegram   ☑ email   ☐ viber   ☐ signal
```

to a multi-select of alert profiles:

```
Alert Profiles: [family ×] [work ×]  [+ Add]
```

Implemented as a tag-style multi-select populated from `alert_profiles_list`.
The legacy `alert_channels` field is still saved for backwards compatibility
when no profiles are selected (so existing routes keep working).

---

## Rate Limiting

No changes. Rate limiting remains keyed by `route_id` in `alert_counts.json`,
applying regardless of how many profiles or channel profiles receive the alert.

Per-profile or per-recipient rate limiting is a potential future enhancement,
out of scope for this plan.

---

## File Change Summary

| File | Change |
|---|---|
| `schema.php` | 5 new tables; add `alert_profile_ids` to `routes`; update `--reset` |
| `Config.php` | Profile lookup + CRUD methods; new `getRouteAlertProfileIds()` |
| `AlertManager.php` | Dual-path dispatch; refactored `send*Cfg()` methods; new test helpers |
| `api.php` | `channel_profiles_*` and `alert_profiles_*` action groups |
| `settings.php` | Reorganised Alerts tab; channel profile tables; alert profile composer |
| `settings.js` | Profile CRUD functions; route form tag-select for alert profiles |

No changes to: `collector.php`, `advisor.php`, `DepartureAdvisor.php`,
`dashboard.php`, `dashboard.js`, `map.php`, `auth.php`, `login.php`.

---

## Testing Checklist

1. `php schema.php --init` on a clean DB creates all 5 new tables.
2. `php schema.php --init` on an existing DB is non-destructive.
3. `php schema.php --reset` recreates all tables correctly.
4. Routes with legacy `alert_channels` and no `alert_profile_ids` behave
   identically to today — no regression.
5. A route assigned to one alert profile dispatches to all channel profiles in
   that profile.
6. A route assigned to two alert profiles dispatches independently to each.
7. A disabled channel profile is skipped; other bindings in the same alert
   profile still fire.
8. A disabled alert profile is skipped entirely.
9. Deleting a channel profile that is in use returns a validation error.
10. Deleting an alert profile assigned to a route returns a validation error.
11. Test actions (per channel profile, per alert profile, per route) send
    messages to the correct recipients only.
12. `php collector.php --test-alerts` uses profile dispatch for profile-enabled
    routes, legacy dispatch for legacy routes.
13. Settings UI: create → edit → test → delete round-trip for each channel type.
14. Settings UI: alert profile composer correctly saves and reloads channel
    bindings.
15. Route editor: assigning and removing alert profiles persists correctly.
