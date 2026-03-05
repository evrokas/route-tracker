# Telegram Alert Profiles — Implementation Plan

## Overview

Currently, v3 has a single global Telegram configuration: one bot token and one
flat comma-separated list of `chat_ids` stored in the `settings` table. Every
route that includes `telegram` in its `alert_channels` sends to **all** those
chat IDs with no distinction.

The goal of this feature is to allow **named Telegram profiles**, each with its
own bot token and target chat IDs, so different routes (or different alert
types) can reach different people or groups.

**Example use case:**
- Profile `family` → sends to the family group chat
- Profile `dad` → sends only to the father's personal chat
- Profile `work` → sends to a work-related group
- A route like "Son → School" uses `telegram:family`
- A route like "Vangelis → Work" uses `telegram:dad` and `telegram:work`

---

## Current State (v3 Baseline)

| Layer | Current Behaviour |
|---|---|
| `settings` table | `telegram_enabled`, `telegram_bot_token`, `telegram_chat_ids` (comma-sep) |
| `routes.alert_channels` | JSON array of plain strings: `["telegram", "email"]` |
| `Config::getAlertConfig('telegram')` | Reads single global token + chat IDs |
| `AlertManager::sendTelegram()` | Sends to all chat IDs using the global token |
| Settings UI | Single toggle + token + chat IDs text field |

---

## Proposed Design

### Channel Name Convention

Routes reference Telegram profiles using a `telegram:<profile_id>` notation in
their `alert_channels` JSON array:

```json
["email", "telegram:family", "telegram:dad"]
```

The bare string `"telegram"` continues to work as a fallback that maps to a
built-in default profile (see Migration section).

### New Database Table: `telegram_profiles`

```sql
CREATE TABLE telegram_profiles (
    id         TEXT PRIMARY KEY,   -- slug, e.g. "family", "dad", "work"
    label      TEXT NOT NULL,      -- human name, e.g. "Family Group"
    bot_token  TEXT NOT NULL,
    chat_ids   TEXT NOT NULL,      -- comma-separated, same format as current
    enabled    INTEGER DEFAULT 1,
    created_at TEXT,
    updated_at TEXT
);
```

The existing global settings keys (`telegram_enabled`, `telegram_bot_token`,
`telegram_chat_ids`) are **preserved** and treated as the implicit `default`
profile for backwards compatibility.

---

## File-by-File Changes

### 1. `schema.php`

**What changes:**
- Add `CREATE TABLE IF NOT EXISTS telegram_profiles (…)` in the init block.
- Add the table to the `DROP TABLE` list in the `--reset` block.
- No seed rows — profiles are created by the user via the Settings UI.

**Migration path for existing installations:**
When `--init` is run on an existing DB (safe re-run), the table is created
empty. Existing routes that use `"telegram"` keep working via the legacy
global-config path (no data migration needed).

---

### 2. `Config.php`

**`getAlertConfig(string $channel): array`**

Extend the `switch` to handle the `telegram:*` prefix:

```
case starts with 'telegram:':
    $profileId = substring after ':'
    look up telegram_profiles table by id
    if not found or not enabled → return ['enabled' => false]
    return ['enabled' => true, 'bot_token' => …, 'chat_ids' => parseCommaSeparated(…)]
case 'telegram':
    existing behaviour (global settings) — unchanged
```

**`getRouteAlertChannels(array $route): array`**

Extend `isAlertEnabled()` to also recognise `telegram:<id>` strings:

```
if channel starts with 'telegram:':
    profile = DB lookup by id
    return profile exists && profile.enabled == 1
else:
    existing logic (global enabled flag from settings)
```

**New method: `getTelegramProfiles(): array`**

Returns all rows from `telegram_profiles` ordered by `label`, used by the
Settings UI and API.

**New methods for CRUD (called from `api.php`):**
- `saveTelegramProfile(array $data): void` — insert or replace
- `deleteTelegramProfile(string $id): void`

---

### 3. `AlertManager.php`

**`dispatch(array $channels, …)`**

The `switch` currently maps `'telegram'` → `sendTelegram()`. Extend it to also
route `telegram:*` strings to the same method, passing the resolved config:

```php
if ($channel === 'telegram' || str_starts_with($channel, 'telegram:')) {
    $ok = $this->sendTelegramChannel($channel, $body);
}
```

**New private method: `sendTelegramChannel(string $channel, string $body): bool`**

Replaces the current `sendTelegram()` private method:

1. Call `$this->config->getAlertConfig($channel)` — returns config for either
   the global default or a named profile.
2. If `enabled` is false → return false early.
3. Otherwise iterate `chat_ids` and POST to the Telegram Bot API exactly as
   the current `sendTelegram()` does.

The existing `sendTelegram()` method is either removed (replaced) or becomes a
thin wrapper calling `sendTelegramChannel('telegram', $body)`.

**`sendTestChannel(string $channel): array`**

Extend to accept `telegram:<id>` strings in addition to plain `'telegram'`,
delegating to `sendTelegramChannel()`.

---

### 4. `api.php`

Add a new action group `telegram_profiles` with sub-actions:

| Action | Method | Description |
|---|---|---|
| `telegram_profiles_list` | GET | Return all profiles as JSON array |
| `telegram_profiles_save` | POST | Insert or update a profile |
| `telegram_profiles_delete` | POST | Delete a profile by id |
| `telegram_profiles_test` | POST | Send a test message via a profile |

All actions require an active session (same `Auth::requireLoginOrJson()` guard
as other API actions).

**`telegram_profiles_save` payload:**
```json
{
  "id": "family",
  "label": "Family Group",
  "bot_token": "123456:ABC…",
  "chat_ids": "-1001234567890, 987654321",
  "enabled": true
}
```

**Validation rules:**
- `id` must match `/^[a-z0-9_-]+$/` (slug-safe)
- `id` must not be `"default"` (reserved for the legacy global config)
- `label` must not be empty
- `bot_token` must not be empty
- At least one `chat_id` must be present

---

### 5. `settings.php` + `settings.js`

**`settings.php` (HTML)**

Inside the existing Telegram channel card, below the current fields, add a
collapsible **"Telegram Profiles"** subsection:

- A table listing existing profiles: columns `ID`, `Label`, `Chat IDs`,
  `Enabled`, `Actions` (Edit / Delete / Test).
- An **"Add Profile"** button that opens an inline form (or expands a form
  below the table).
- The inline form has fields: ID (slug), Label, Bot Token (password type),
  Chat IDs (text), Enabled toggle.
- A **"Save Profile"** button and a **"Cancel"** button.
- A **"Test"** button per row that calls `telegram_profiles_test`.

The default global Telegram configuration (bot token + chat IDs) is kept as-is
above the profiles subsection with a note: *"Used by routes that specify
`telegram` without a profile name."*

**`settings.js`**

New functions:
- `loadTelegramProfiles()` — GET `api.php?action=telegram_profiles_list`,
  renders the table.
- `saveTelegramProfile(data)` — POST to `telegram_profiles_save`.
- `deleteTelegramProfile(id)` — POST to `telegram_profiles_delete` with
  confirmation dialog.
- `testTelegramProfile(id)` — POST to `telegram_profiles_test`, show
  success/failure toast.
- `editTelegramProfile(id)` — populates the inline form for editing.

Call `loadTelegramProfiles()` on page load alongside other settings loads.

---

### 6. Route Editing (Settings UI — Routes Tab)

The route `alert_channels` field in the route form needs to be updated so users
can select Telegram profiles, not just bare `"telegram"`.

**Current:** A multi-select or checkbox list with options `email`, `telegram`,
`viber`, `signal`.

**After:** The `telegram` option is replaced (or supplemented) by a dynamic
list of profiles fetched from `telegram_profiles_list`. Each profile appears
as a selectable option labelled `Telegram: <label>` and stores the value
`telegram:<id>`. The legacy bare `telegram` option is still listed as
`Telegram (default)` for routes that don't need a specific profile.

If no profiles exist, the behaviour is identical to today (only `telegram`
shown).

---

## Migration for Existing Data

No data migration is required:
- Existing routes using `"telegram"` in `alert_channels` continue to work
  unchanged — the code falls through to the global config path.
- Existing `settings` keys are not removed.
- New profile table is additive.

---

## Rate Limiting Behaviour

Rate limiting (max alerts per day) is keyed by `route_id` in
`alert_counts.json`. This is **not** changed by this feature — limits apply
per route regardless of how many Telegram profiles receive the alert.

If per-profile rate limiting is needed in the future, the key could be
extended to `route_id:channel` but that is out of scope for this plan.

---

## Testing Checklist

1. `php schema.php --init` on a clean DB creates the `telegram_profiles` table.
2. `php schema.php --init` on an existing DB adds the table without data loss.
3. `php schema.php --reset` drops and recreates the table correctly.
4. A route with `alert_channels: ["telegram"]` behaves identically to today.
5. A route with `alert_channels: ["telegram:family"]` sends only to the
   `family` profile's chat IDs using its bot token.
6. A route with `alert_channels: ["telegram:family", "telegram:dad"]` sends
   separate messages to each profile's chat IDs.
7. A disabled profile (`enabled = 0`) is silently skipped.
8. A referenced profile that does not exist is silently skipped (logged).
9. `php collector.php --test-alerts` sends test messages to all enabled
   channels including all enabled profiles.
10. The Settings UI can create, edit, delete, and test profiles.
11. The route editor lists available profiles and saves the correct channel
    strings.

---

## Files Changed Summary

| File | Type of Change |
|---|---|
| `schema.php` | Add `telegram_profiles` table creation + reset |
| `Config.php` | Extend `getAlertConfig()`, `isAlertEnabled()`, add CRUD helpers |
| `AlertManager.php` | Extend `dispatch()` + refactor `sendTelegram()` into `sendTelegramChannel()` |
| `api.php` | Add `telegram_profiles_*` action handlers |
| `settings.php` | Add Profiles subsection inside Telegram card |
| `settings.js` | Add profile management functions |

No new PHP files required. No changes to `collector.php`, `advisor.php`,
`dashboard.js`, `dashboard.php`, `auth.php`, or `map.php`.
