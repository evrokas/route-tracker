# Dashboard Tab Consolidation Plan

## Current State (6 tabs)

| Tab | ID | What it shows |
|-----|----|---------------|
| Advisor | `advisor` | "Leave by X" countdown, live duration, stage progress, time-window filter |
| Overview | `overview` | Avg/best/worst stat cards per route+schedule, bar chart |
| Best Routes | `best` | Which specific road is fastest per day (requires `request_alternatives`) |
| By Day | `byday` | Avg duration by day-of-week, bar chart + table |
| Trends | `trends` | Timeline line chart, monthly averages, road comparison table |
| History | `history` | Raw table of last 100 trips (timestamps, API status) |

## Assessment

- **Advisor** — keep, it's the core feature; has auto-refresh, countdown ticks, time-window chips
- **Overview** — keep, it's the daily aggregate summary
- **Best Routes** + **By Day** — overlap: both are day-of-week breakdowns.
  Best Routes shows *which road* is fastest; By Day shows *how long* each day
  takes. These can be merged into one "By Day" tab.
- **Trends** — keep as-is; answers "how has my commute changed over time?"
  (timeline, monthly, road comparison are all unique views)
- **History** — raw data log, rarely needed; move to a collapsible "Recent Trips"
  `<details>` section at the bottom of Overview

## Proposed Tab Structure (4 tabs)

| Tab | Contents |
|-----|----------|
| Advisor | (unchanged) |
| Overview | (unchanged) + collapsible "Recent Trips" `<details>` at the bottom |
| By Day | Current By Day content (chart + table) + **Best Routes section** appended below |
| Trends | (unchanged) |

---

## Implementation Details

### dashboard.php

1. Remove "Best Routes" tab button:
   ```html
   <!-- remove this line -->
   <button class="tab-btn" data-tab="best">Best Routes</button>
   ```
2. Remove "History" tab button:
   ```html
   <!-- remove this line -->
   <button class="tab-btn" data-tab="history">History</button>
   ```
3. Rename "By Day" label to "By Day" (keep as-is, or rename to "By Day & Roads" for clarity — decide at implementation time).

Result: 4 tab buttons — Advisor, Overview, By Day, Trends.

---

### dashboard.js

#### Tab switch handler
Remove `case 'best'` and `case 'history'` from the `switch(tab)` block (or the equivalent `if/else` chain that calls render functions). Only 4 cases remain.

#### renderByDay() — merge Best Routes
Change from a single `fetch(by_day)` to `Promise.all`:

```js
// Before:
fetch api?action=by_day → render table + chart

// After:
Promise.all([
  fetch api?action=by_day,
  fetch api?action=best_routes
]).then(([byDayData, bestData]) => {
  renderByDayTable(byDayData);   // existing table + chart
  renderBestSection(bestData);   // existing best-card grid, appended below
});
```

The existing `renderBest()` logic (building `.best-grid` / `.best-card` elements) can be
extracted into a helper `renderBestSection(data, container)` called from both the old
`renderBest()` (now unused) and the new `renderByDay()` path.

Cache keys: `byday` and `best_routes` remain separate so each can be invalidated independently.

#### renderOverview() — add Recent Trips collapsible
Append a `<details>` element at the end of the Overview content:

```html
<details class="recent-trips-details">
  <summary>Recent Trips</summary>
  <!-- lazy-loaded table via collections API, limit 20 -->
</details>
```

- Load data only on `<details>` `toggle` event (first open), not on tab render
- Reuse existing `renderHistory()` table-building logic with `limit=20`
- Uses existing `.table-wrap`, `table`, `.badge-arrive`, `.badge-depart` styles — no new CSS needed

#### renderBest() / renderHistory()
Keep both functions in the file (as private helpers) but stop calling them from the tab switch. `renderBest()` logic gets delegated to a shared helper; `renderHistory()` gets called lazily from the `<details>` toggle in Overview.

#### Auto-refresh / tab state
No changes needed — `startAutoRefresh()` already guards on Advisor tab only. The tab switch handler change is enough.

---

### dashboard.css

No structural changes. Existing classes used:
- `.best-grid`, `.best-card`, `.best-card-header`, `.best-card-day`, `.best-card-route`,
  `.best-card-road`, `.best-card-stats`, `.alts`, `.alt-row` — reused inside By Day tab
- `.table-wrap`, `table`, `.badge-arrive`, `.badge-depart` — reused in Recent Trips collapsible

Optional: add a small rule for the `<details>` toggle section in Overview if it needs margin/border, but no tab-specific changes.

---

## Files to Change

| File | Change |
|------|--------|
| `web/dashboard.php` | Remove "Best Routes" and "History" tab buttons (2 line deletions) |
| `web/js/dashboard.js` | (a) Remove `case 'best'` and `case 'history'` from tab switch; (b) merge `best_routes` fetch into `renderByDay()` via `Promise.all`; (c) add lazy-loaded `<details>` Recent Trips at bottom of `renderOverview()` |
| `web/css/dashboard.css` | Minor: optional `<details>` margin rule only |

---

## What Does NOT Change

- All 6 API endpoints stay (`best_routes`, `by_day`, `collections`, `timeline`, `by_month`, `by_route_name`)
- Filter bar (route chips + time chips) logic is unchanged; time chips already only show on Advisor tab
- Quick Trip split button in header — unchanged
- Auto-refresh / countdown tick — unchanged
- Auth, PHP backend — no changes

---

## Open Question

Rename "By Day" tab to "By Day & Roads"? It now contains two merged views.
Decide at implementation time — a label change is one word in `dashboard.php`.
