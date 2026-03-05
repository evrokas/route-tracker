# Alert Profiles — v3 Enhancement Plan

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

Route "Son → School"    → alert_profile_ids: ["family"]
Route "Vangelis → Work" → alert_profile_ids: ["family", "work"]
Route "Server Monitor"  → alert_profile_ids: ["ops"]
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
channels   TEXT NOT NULL      -- JSON array of channel-profile bindings
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
- **Replace** `alert_channels TEXT DEFAULT '[]'` with `alert_profile_ids TEXT DEFAULT '[]'`
- `alert_profile_ids` holds a JSON array of alert profile id strings

#### `settings` table
- **Remove** the following keys from seed defaults and from all reads:
  `telegram_enabled`, `telegram_bot_token`, `telegram_chat_ids`,
  `email_enabled`, `email_host`, `email_port`, `email_user`, `email_pass`,
  `email_from`, `email_to`,
  `viber_enabled`, `viber_auth_token`, `viber_receiver_ids`,
  `signal_enabled`, `signal_api_url`, `signal_sender`, `signal_recipients`
- These keys are no longer needed; all channel config lives in the profile tables.

---

## `Config.php` Changes

### Removed Methods

The following methods are deleted entirely:
- `getAlertConfig(string $channel)` — replaced by per-type profile lookups
- `isAlertEnabled(string $channel)` — no longer relevant
- `getRouteAlertChannels(array $route)` — replaced by `getRouteAlertProfiles()`

### New Channel Profile Lookups

```
getTelegramProfile(id)  → array|null
getEmailProfile(id)     → array|null
getSignalProfile(id)    → array|null
getViberProfile(id)     → array|null
```

Each queries the corresponding profile table and returns a normalised config
array ready for `AlertManager`, or `null` if the id is not found or disabled.

### New Alert Profile Methods

```
getAlertProfile(id)           → array|null   -- single profile row
getAllAlertProfiles()          → array         -- all profiles ordered by label
getRouteAlertProfiles(route)  → array         -- profiles assigned to this route
```

`getRouteAlertProfiles()` reads `alert_profile_ids` from the route, fetches
each profile by id, and filters out disabled ones.

### CRUD Helpers (called from `api.php`)

```
saveChannelProfile(type, data)    -- type in {telegram, email, signal, viber}
deleteChannelProfile(type, id)
saveAlertProfile(data)
deleteAlertProfile(id)
```

---

## `AlertManager.php` Changes

### Dispatch Flow (simplified)

`evaluateAndAlert()` resolves alert profiles exclusively:

```
profiles = config->getRouteAlertProfiles(route)
if profiles is empty → return (nothing to do)

for each profile:
    dispatchProfile(profile, subject, body, route)
```

The old `dispatch(channels, …)` method and the flat-channel branch are removed.

### `dispatchProfile(profile, subject, body, route)`

```
for each binding in profile.channels:
    cfg = config->get<Type>Profile(binding.profile_id)
    if cfg is null or disabled → log and skip
    switch binding.type:
        telegram → sendTelegram(cfg, body)
        email    → sendEmail(cfg, subject, body)
        signal   → sendSignal(cfg, body)
        viber    → sendViber(cfg, body)
    log result
```

### Send Methods (profile-only signatures)

All four send methods accept a pre-resolved config array — no global settings
fallback:

```
sendTelegram(cfg, body)          -- cfg from getTelegramProfile()
sendEmail(cfg, subject, body)    -- cfg from getEmailProfile()
sendSignal(cfg, body)            -- cfg from getSignalProfile()
sendViber(cfg, body)             -- cfg from getViberProfile()
```

The old zero-argument versions that pulled from global settings are removed.

### Test Methods

```
sendTest(?routeId)                       -- dispatches via profiles for route(s)
sendTestAlertProfile(profileId)          -- sends test to all channel profiles in bundle
sendTestChannelProfile(type, profileId)  -- sends test via one specific channel profile
```

`sendTestChannel(string $channel)` (the old plain-channel test) is removed.

---

## `api.php` Changes

All alert-related legacy actions (`test_alert`, any flat channel actions) are
removed. New action groups:

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
- `label` must not be empty
- Channel-profile-specific required fields validated per type
- Cannot delete a channel profile that is referenced by an alert profile
- Cannot delete an alert profile that is referenced by a route

---

## Settings UI Changes (`settings.php` + `settings.js`)

### Alerts Tab Structure

The existing per-channel config blocks (single global bot token field, single
SMTP block, etc.) are **removed entirely**. The Alerts tab is restructured:

```
Alerts
├── Global Settings   (thresholds, rate limits — unchanged)
├── Channel Profiles
│   ├── Telegram      [table + add/edit/delete/test]
│   ├── Email         [table + add/edit/delete/test]
│   ├── Signal        [table + add/edit/delete/test]
│   └── Viber         [table + add/edit/delete/test]
└── Alert Profiles    [table + composer + add/edit/delete/test]
```

### Channel Profile UI (one per channel type)

Each channel type section shows:
- A table: `ID | Label | Target(s) | Enabled | Actions`
- **Actions per row:** Edit (inline form expand) / Delete (with guard if in
  use) / Test (fires `channel_profiles_test`, shows toast)
- **"+ Add profile"** button opens the inline form below the table
- Form fields vary by channel type (bot token + chat IDs for Telegram; SMTP
  fields + recipients for Email; etc.)

### Alert Profile UI

- A table: `ID | Label | Channels | Enabled | Actions`
- **Channels column** shows compact badges: `TG: family_bot`, `Email: personal`
- **Edit form** includes a channel binding composer:
  - A "+" button adds a binding row
  - Each row: channel type dropdown → profile dropdown (populated from saved
    profiles of that type) → remove button
- **Test button** per row fires `alert_profiles_test`

### Route Form Update

The route editor's alert assignment replaces the old channel checkbox list with
a tag-style multi-select of alert profiles:

```
Alert Profiles: [family ×] [work ×]  [+ Add]
```

Populated from `alert_profiles_list`. Saves to `alert_profile_ids`.
The `alert_channels` field is removed from the route form and the route save
handler.

---

## Rate Limiting

No changes. Rate limiting remains keyed by `route_id` in `alert_counts.json`,
applying regardless of how many profiles or channel profiles receive the alert.

---

## File Change Summary

| File | Change |
|---|---|
| `schema.php` | 5 new tables; replace `alert_channels` with `alert_profile_ids` on routes; remove channel settings keys |
| `Config.php` | Remove `getAlertConfig`, `isAlertEnabled`, `getRouteAlertChannels`; add profile lookup + CRUD methods |
| `AlertManager.php` | Remove flat-channel dispatch and global-settings send methods; profile-only dispatch |
| `api.php` | Remove legacy channel actions; add `channel_profiles_*` and `alert_profiles_*` groups |
| `settings.php` | Remove per-channel global config blocks; add channel profile tables + alert profile composer |
| `settings.js` | Remove channel save/load functions; add profile CRUD + route form tag-select |

No changes to: `collector.php`, `advisor.php`, `DepartureAdvisor.php`,
`dashboard.php`, `dashboard.js`, `map.php`, `auth.php`, `login.php`.

---

## Testing Checklist

1. `php schema.php --init` on a clean DB creates all 5 new tables and the
   updated `routes` schema.
2. `php schema.php --reset` drops and recreates everything cleanly.
3. A route with no `alert_profile_ids` sends no alerts (no silent fallback).
4. A route assigned to one alert profile dispatches to all channel profiles in
   that profile.
5. A route assigned to two alert profiles dispatches independently to each.
6. A disabled channel profile is skipped; other bindings in the same alert
   profile still fire.
7. A disabled alert profile is skipped entirely.
8. Deleting a channel profile that is in use returns a validation error.
9. Deleting an alert profile assigned to a route returns a validation error.
10. Test actions (per channel profile, per alert profile, per route) send
    messages to the correct recipients only.
11. `php collector.php --test-alerts` dispatches via profiles for all routes.
12. Settings UI: create → edit → test → delete round-trip for each channel type.
13. Settings UI: alert profile composer correctly saves and reloads channel
    bindings.
14. Route editor: assigning and removing alert profiles persists correctly.
