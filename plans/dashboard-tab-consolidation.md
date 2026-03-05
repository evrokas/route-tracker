# Dashboard Tab Consolidation Plan

## Current State (6 tabs)

| Tab | What it shows |
|-----|---------------|
| Advisor | "Leave by X" countdown, live duration, stage progress |
| Overview | Avg/best/worst stat cards per route+schedule, bar chart |
| Best Routes | Which specific road is fastest per day (requires `request_alternatives`) |
| By Day | Avg duration by day-of-week, bar chart + table |
| Trends | Timeline line chart, monthly averages, road comparison table |
| History | Raw table of last 100 trips (timestamps, API status) |

## Assessment

- **Advisor** — keep, it's the core feature of v3
- **Overview** — keep, it's the daily aggregate summary
- **Best Routes** + **By Day** — overlap: both are day-of-week breakdowns.
  Best Routes shows *which road* is fastest; By Day shows *how long* each day
  takes. These can be merged into one "By Day" tab.
- **Trends** — keep as-is; answers "how has my commute changed over time?"
  (timeline, monthly, road comparison are all unique views)
- **History** — raw data log, rarely needed; consider dropping or moving to
  Settings → System tab

## Proposed Tab Structure (4-5 tabs)

| Tab | Contents |
|-----|----------|
| Advisor | (unchanged) |
| Overview | (unchanged) |
| By Day | Avg duration chart + day/road table + **Best Road section** (merged from Best Routes tab) |
| Trends | (unchanged) |
| ~~History~~ | Drop tab; optionally add a "Recent Trips" table as a collapsible section inside Overview or move to Settings → System |

## Implementation Notes

### Merging Best Routes into By Day (`dashboard.js`)

- Remove `case 'best'` from the tab switch and `renderBest()` call
- Append best-road cards/section at the bottom of `renderByDay()`
- The `best_routes` API call can be made inside `renderByDay()` alongside the
  existing `by_day` call (use `Promise.all`)
- Remove the Best Routes tab button from `dashboard.php`

### Dropping History tab

- Remove `case 'history'` from the tab switch and `renderHistory()` call
- Remove the History tab button from `dashboard.php`
- Optionally: add a "Recent Trips" collapsible `<details>` at the bottom of
  Overview, loading last 20 trips on open (lazy, not on tab load)

### API changes

- No changes needed — `best_routes`, `by_day`, `collections` endpoints stay
- `renderByDay()` adds a `Promise.all` for the `best_routes` call

### CSS changes

- No structural changes needed; existing `.best-card`, `.best-grid` styles stay
  and are reused inside the By Day tab

## Files to Change

| File | Change |
|------|--------|
| `dashboard.php` | Remove "Best Routes" and "History" tab buttons |
| `dashboard.js` | Remove `renderBest()` and `renderHistory()` tab cases; merge best-road rendering into `renderByDay()`; optionally add collapsible recent trips in overview |
