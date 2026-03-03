# Route Tracker — Enhancement Plan v3

## Overview

This document outlines proposed enhancements beyond the v2 baseline, organized by priority and complexity. The core theme is evolving from **passive data collection** to an **active intelligent travel assistant** for the family.

---

## Enhancement 1: Departure Time Advisor (Priority: HIGH)

**Vangelis's original idea — the most impactful enhancement.**

### The Problem

Currently the system collects data and shows statistics. But the real question each morning is: **"What time should I leave to arrive at work by 07:30?"** — and the answer changes daily based on traffic patterns.

### How It Works

The system runs a **countdown advisor** that starts checking traffic 90 minutes before the target arrival time. It continuously calculates the latest safe departure time and sends progressive alerts.

#### Advisor Stages

| Stage | When | Alert Type | Message Example |
|-------|------|-----------|-----------------|
| **Planning** | 90 min before arrival | Informational | "Traffic looks normal. Leave by 06:55 to arrive by 07:30." |
| **Window opening** | 60 min before arrival | First alert | "Recommended departure: 06:50. Current estimate via Συγγρού: 35 min." |
| **Reminder** | 30 min before calculated departure | Nudge | "Leave in 30 minutes (by 06:50) for 07:30 arrival." |
| **Urgent** | 10 min before calculated departure | Urgent push | "⚠️ Leave NOW. Traffic is building — 38 min via Χαμοστέρνας." |
| **Last call** | At calculated departure time | Final alert | "🚨 Departure time! Best route: Ποσειδώνος (32 min)." |
| **Late warning** | If still not departed (optional) | Warning | "If you leave now, ETA is 07:42 (+12 min late). Consider alternate route." |

#### Calculation Method

1. Query Google Directions API with `departure_time=now`
2. Take the fastest route's `duration_in_traffic`
3. Add a **buffer** based on historical variance for this day/time:
   - If route is consistent (stddev < 3 min) → add 5 min buffer
   - If route is variable (stddev 3–8 min) → add 10 min buffer
   - If route is unpredictable (stddev > 8 min) → add 15 min buffer
4. Recommended departure = arrival_time − duration_in_traffic − buffer
5. Recalculate every 5 minutes as departure approaches (traffic changes)

#### Configuration in `routes.yaml`

```yaml
- id: dad_work
  label: "Vangelis → Work"
  origin: "Ellispontou 61, Nea Smyrni"
  destination: "Work Address"
  schedule:
    - days: Weekdays
      arrive: "07:30"
  advisor:
    enabled: true
    start_before_minutes: 90       # Start checking 90 min before arrival
    check_interval_minutes: 5      # Re-check every 5 min
    buffer_mode: auto              # auto (from historical data), or fixed
    fixed_buffer_minutes: 10       # Used if buffer_mode=fixed
    alert_stages:                  # Which alerts to send
      - planning                   # 90 min before
      - window                     # 60 min before
      - reminder                   # 30 min before departure
      - urgent                     # 10 min before departure
      - last_call                  # At departure time
```

#### Cron Strategy

For arrive mode at 07:30 with 90 min lookahead:
- Cron runs every 5 minutes from 06:00 to 07:15
- Each run: check current traffic → calculate departure → compare with alert stages → send if stage threshold crossed
- A state file (`data/advisor_state.json`) tracks which stages have already fired today to avoid duplicate alerts

#### New Component: `DepartureAdvisor.php`

Responsibilities:
- Load historical average + stddev for route/day/time
- Query live traffic via Directions API
- Calculate recommended departure with confidence-based buffer
- Track alert stage progression
- Format and dispatch alerts via AlertManager

---

## Enhancement 2: Return Trip Tracking (Priority: HIGH)

### The Problem

Every outbound trip has a return. The son needs to come home from the learning center. Parents need to drive home from work. Traffic patterns are often very different for the return direction.

### Implementation

Each route in YAML gains an optional `return` block:

```yaml
- id: son_learning
  label: "Son → Learning Center"
  origin: "Ellispontou 61, Nea Smyrni"
  destination: "Makrigianni 100, Moschato"
  schedule:
    - days: Mon
      depart: "16:00"
  return:
    label: "Son ← Learning Center"
    schedule:
      - days: Mon
        depart: "18:30"     # Lesson ends, depart to come home
```

The system auto-generates a reverse route (swapping origin/destination) with its own `route_id` = `son_learning_return`. This keeps the database clean — return trips have their own statistics, as traffic patterns differ by direction.

For work commutes, the return could use the advisor in reverse:
```yaml
  return:
    label: "Vangelis ← Work"
    schedule:
      - days: Weekdays
        depart: "15:00"     # Or: arrive: "15:30" at home
    advisor:
      enabled: true
```

---

## Enhancement 3: Greek Holiday & School Calendar Awareness (Priority: HIGH)

### The Problem

Work routes should not collect data or send alerts on public holidays. School-related routes should pause during summer break, Christmas/Easter holidays, and snow days. The system currently doesn't know about any of this.

### Implementation

New config file: `calendar.yaml`

```yaml
# Greek Public Holidays (fixed dates)
public_holidays:
  - date: "01-01"
    name: "Πρωτοχρονιά"
  - date: "01-06"
    name: "Θεοφάνεια"
  - date: "03-25"
    name: "Ευαγγελισμός"
  - date: "05-01"
    name: "Πρωτομαγιά"
  - date: "08-15"
    name: "Κοίμηση Θεοτόκου"
  - date: "10-28"
    name: "Ημέρα του Όχι"
  - date: "12-25"
    name: "Χριστούγεννα"
  - date: "12-26"
    name: "Σύναξη Θεοτόκου"

# Moving holidays (calculated from Orthodox Easter)
# System calculates these automatically each year
orthodox_easter_based:
  - offset: -48
    name: "Καθαρά Δευτέρα"
  - offset: -2
    name: "Μεγάλη Παρασκευή"
  - offset: 0
    name: "Πάσχα"
  - offset: 1
    name: "Δευτέρα του Πάσχα"
  - offset: 50
    name: "Αγίου Πνεύματος"

# School calendar (approximate, editable)
school_breaks:
  christmas:
    start: "12-24"
    end: "01-07"
  easter:
    # Calculated from Orthodox Easter: -7 days to +7 days
    offset_start: -7
    offset_end: 7
  summer:
    start: "06-15"
    end: "09-10"

# Route-specific calendar rules
route_rules:
  son_learning:
    skip_on: [public_holidays, school_breaks]
  dad_work:
    skip_on: [public_holidays]
  mom_work:
    skip_on: [public_holidays]
```

The collector checks the calendar before running. If today is a skip day for a route, it logs "Skipping: holiday" and doesn't collect or alert.

**Bonus:** Tag collected data with `is_holiday_adjacent: true` for days before/after holidays (traffic patterns change on these days too — e.g., Friday before a long weekend).

---

## Enhancement 4: Weekly Digest Report (Priority: MEDIUM)

### The Problem

Daily alerts are useful, but a weekly summary helps spot trends and make longer-term decisions (e.g., "Should I permanently switch my commute route?").

### Implementation

New script: `digest.php` — runs via cron every Sunday evening.

Generates and sends a summary:

```
📊 Route Tracker — Weekly Digest
Week 12 (Mar 17–21, 2025)

═══ Vangelis → Work ═══
  Trips this week: 5
  Average: 28.3 min (▼ 2.1 min vs last week)
  Best: Mon 25.1 min via Συγγρού
  Worst: Thu 38.7 min via Πειραιώς
  Recommendation: Συγγρού remains your most reliable route

═══ Son → Learning Center ═══
  Trips this week: 3
  Average: 14.2 min (▲ 1.5 min vs last week)
  Thursday 18:00 was 22 min — worst this month
  Recommendation: Try Χαμοστέρνας on Thursdays

═══ Trends ═══
  Traffic is increasing — typical for late March
  as schools return from spring activities.
  Next week: expect similar or slightly worse.
```

**Delivery channels:** Same as alerts (email, Telegram, etc.) — configurable per route.

**Configuration:**
```yaml
digest:
  enabled: true
  day: Sunday          # Day to send digest
  time: "20:00"        # Time to send
  channels: [email, telegram]
  include_charts: true # Attach a simple chart image (optional)
```

---

## Enhancement 5: Weather-Traffic Correlation (Priority: MEDIUM)

### The Problem

Rain in Athens dramatically increases commute times. If the system knows it's raining (or about to rain), it can adjust buffer times and send earlier departure warnings.

### Implementation

Integrate a free weather API (e.g., OpenWeatherMap — free tier: 1000 calls/day).

New config in `config.yaml`:
```yaml
weather:
  enabled: true
  api_provider: openweathermap
  api_key: "your-openweathermap-key"
  location_lat: 37.9445    # Nea Smyrni
  location_lon: 23.7166
```

**What changes with weather data:**

1. **Collection:** Each collection row gets weather columns: `temperature`, `weather_condition` (clear/clouds/rain/storm), `rain_mm`
2. **Analysis:** Dashboard shows duration vs weather correlation — "Rain adds average 8 min to your Thursday commute"
3. **Advisor adjustment:** Rain forecast → increase departure buffer by historical rain penalty
4. **Proactive alert:** Evening before a rainy morning: "Tomorrow's forecast: rain from 06:00. Expect +8 min on your commute. Consider leaving at 06:40 instead of 06:50."

---

## Enhancement 6: Real-Time Trip Monitoring (Priority: MEDIUM)

### The Problem

Once you leave, traffic can change. What if there's an accident on your chosen route 5 minutes after departure?

### Implementation

A lightweight **"trip mode"** triggered via Telegram bot command or a simple web button:

1. User sends `/start dad_work` to Telegram bot (or taps a button on the dashboard)
2. System begins polling Google Directions API every 3 minutes from current location to destination
3. If ETA changes significantly (>5 min increase) or a faster alternative appears, sends mid-trip alert:

```
🚗⚡ Route Update!

Your current route (Πειραιώς) now shows 35 min remaining.
Switch to Χαμοστέρνας for 22 min remaining (save 13 min).

Next turn: Right on Θηβών in 400m.
```

**Location source options:**
- Phone GPS via a simple PWA page that sends coordinates
- Fixed assumption (you're on the route you started with)
- Google Maps link that opens navigation with the recommended route

**Note:** This enhancement increases API costs. Limit to 3-min intervals for max ~15 calls per trip.

---

## Enhancement 7: Multi-Modal Route Suggestions (Priority: MEDIUM)

### The Problem

On very bad traffic days, public transit or a combination (drive + metro) might actually be faster.

### Implementation

For routes where `travel_mode_alternatives: true` is set, the collector also queries `mode=transit` alongside `mode=driving`. The system can then compare:

```
🚗 Driving via Συγγρού: 45 min (heavy traffic)
🚇 Metro (Νέα Σμύρνη → Μοσχάτο): 28 min
💡 Today transit is 17 min faster. Consider the metro.
```

This is especially useful for the Nea Smyrni area since the metro line 2 extension serves the area.

**Configuration:**
```yaml
- id: dad_work
  travel_mode: driving
  compare_modes: [transit]   # Also check transit times
  transit_alert_threshold: 10  # Alert if transit saves >10 min
```

---

## Enhancement 8: Family Coordination Dashboard (Priority: LOW)

### The Problem

Both parents leave from the same address at similar times. If one parent could drop the other at a metro station on the way, it might save time overall. Or if both are going to nearby destinations, carpooling makes sense.

### Implementation

A **"morning briefing"** message sent to both parents at a configured time (e.g., 06:15):

```
☀️ Morning Briefing — Monday Mar 17

Vangelis → Work
  Best route: Συγγρού, leave by 06:50 (est. 28 min)

Wife → Work
  Best route: Ποσειδώνος, leave by 06:45 (est. 32 min)

Son → Learning Center (today at 16:00)
  Expected: 12 min via Χαμοστέρνας

🌤️ Weather: Clear, 14°C
📅 No holidays this week
```

This is essentially combining the Departure Advisor output for all routes into a single coordinated message.

---

## Enhancement 9: Historical Pattern Prediction (Priority: LOW)

### The Problem

After months of data, we can predict tomorrow's traffic without waiting for real-time data. This enables earlier planning.

### Implementation

A simple prediction model using historical averages with weighted factors:

```
predicted_duration =
    base_avg_for_day_and_time
    × seasonal_factor (month)
    × weather_factor (forecast)
    × holiday_proximity_factor
    × trend_factor (is traffic getting worse over weeks?)
```

This powers:
- **Evening briefing:** "Tomorrow (Thursday) expect heavy traffic. Historical average for Thu 18:00 is 22 min, but trending upward this month. Suggested departure: 17:35."
- **Weekly planning:** Dashboard shows predicted durations for the upcoming week
- **Anomaly detection:** "Today's 45 min trip was 2× the predicted 22 min — flagged as anomaly (possible accident or road closure)"

---

## Enhancement 10: Google Maps Link in Alerts (Priority: LOW)

### The Problem

When the system recommends a specific route (e.g., "Take Χαμοστέρνας"), the user has to manually type it into Google Maps.

### Implementation

Include a deep link in every alert that opens Google Maps navigation with waypoints forcing the recommended route:

```
🚗💡 Better Route Found!
Take Χαμοστέρνας — est. 14 min (saves 6 min)

📍 Navigate: https://www.google.com/maps/dir/?api=1
    &origin=Ellispontou+61,+Nea+Smyrni
    &destination=Makrigianni+100,+Moschato
    &waypoints=Hamosternas+St,+Athens
    &travelmode=driving
```

On mobile (Telegram/Viber), this link opens Google Maps directly with navigation ready.

**Implementation detail:** Store the key intermediate waypoint (main road name) from the route steps. Use it to construct a Google Maps URL with `&waypoints=` parameter to force the specific route.

---

## Enhancement 11: Dashboard Map Visualization (Priority: LOW)

### The Problem

The dashboard shows numbers and charts but no map. Seeing routes on a map makes patterns much more intuitive.

### Implementation

Add a "Map" tab to the dashboard using **Leaflet.js** (open source, no API key needed for tiles) that shows:

- All route alternatives drawn as colored polylines on the map
- Color-coded by average duration (green = fast, red = slow)
- Click a route to see its stats
- Animated playback: watch how routes change speed throughout the day
- Heatmap overlay showing which road segments are slowest

**Data source:** Route steps already store `start_lat/lng` and `end_lat/lng`. Connect the dots to draw each route.

**Note:** This was partially implemented in v1 of the project. Can reuse that Leaflet.js work.

---

## Enhancement 12: Incident & Road Works Awareness (Priority: LOW)

### The Problem

Google Maps API reflects current traffic but doesn't tell you WHY traffic is bad. Knowing about road closures or scheduled works helps with planning.

### Implementation

Periodically scrape or check:
- Google Maps `traffic_model` parameter (best_guess, pessimistic, optimistic) — collect all three for comparison
- Optional: integrate with Athens Traffic Management Center feeds if available
- Optional: parse Google's route warnings for road closure info

Store incidents in a new `incidents` table. Dashboard shows a timeline of when unusual traffic was detected and whether it correlated with known events.

---

## Enhancement 13: API Cost Monitoring (Priority: LOW)

### The Problem

Google Directions API costs money. As routes and frequency increase, costs could creep up.

### Implementation

Track API calls in a `api_usage` table:

| Column | Description |
|--------|-------------|
| date | Date |
| route_id | Which route |
| call_count | Number of API calls |
| estimated_cost | calls × $0.005 |

Dashboard shows a "Cost" section: daily/weekly/monthly API costs, projected monthly cost, alerts if costs exceed a configurable threshold.

---

## Implementation Priority Matrix

| # | Enhancement | Impact | Complexity | API Cost Impact | Priority |
|---|-------------|--------|------------|-----------------|----------|
| 1 | **Departure Time Advisor** | Very High | Medium | +50% (more frequent checks) | **Phase 1** |
| 2 | **Return Trips** | High | Low | +50% (double the routes) | **Phase 1** |
| 3 | **Holiday Calendar** | High | Low | None (reduces unnecessary calls) | **Phase 1** |
| 4 | **Weekly Digest** | Medium | Low | None | **Phase 1** |
| 5 | **Weather Correlation** | Medium | Medium | +minimal (weather API is free) | **Phase 2** |
| 6 | **Real-Time Trip Monitor** | Medium | High | +significant (15 calls/trip) | **Phase 2** |
| 7 | **Multi-Modal Suggestions** | Medium | Low | +30% (transit queries) | **Phase 2** |
| 8 | **Family Morning Briefing** | Medium | Low | None (uses existing data) | **Phase 2** |
| 9 | **Pattern Prediction** | Low-Med | High | None | **Phase 3** |
| 10 | **Google Maps Links** | Low | Very Low | None | **Phase 2** |
| 11 | **Map Visualization** | Low | Medium | None | **Phase 3** |
| 12 | **Incident Awareness** | Low | High | Varies | **Phase 3** |
| 13 | **Cost Monitoring** | Low | Low | None | **Phase 2** |

---

## Estimated API Cost After All Enhancements

| Component | Calls/week | Cost/month |
|-----------|-----------|------------|
| v2 baseline (3 routes × 5 samples × schedule) | ~120 | ~$0.60 |
| + Return trips | +120 | +$0.60 |
| + Departure advisor (5 routes × 5 days × 15 checks) | +375 | +$1.90 |
| + Multi-modal comparisons | +60 | +$0.30 |
| + Real-time trip monitoring (optional, 2 trips/day) | +150 | +$0.75 |
| **Total estimated** | **~825/week** | **~$4.15/month** |

Well within the free tier limits of weather APIs, and very manageable for Google Directions API.

---

## Suggested Implementation Phases

### Phase 1 (Immediate — build with v2)
- Departure Time Advisor with progressive alerts
- Return trip support in YAML
- Greek holiday / school calendar
- Weekly email digest

### Phase 2 (After 1 month of data)
- Weather correlation
- Multi-modal route comparison
- Family morning briefing
- Google Maps navigation links in alerts
- API cost tracking

### Phase 3 (After 3+ months of data)
- Historical prediction model
- Leaflet.js map visualization
- Incident awareness
- Real-time trip monitoring
- Anomaly detection

---

## New Files for Phase 1

| File | Purpose |
|------|---------|
| `DepartureAdvisor.php` | Advisor logic, buffer calculation, stage tracking |
| `advisor.php` | Cron entry point for advisor checks (runs every 5 min) |
| `calendar.yaml` | Holidays, school breaks, route-specific rules |
| `Calendar.php` | Holiday calculation including Orthodox Easter |
| `digest.php` | Weekly digest generator + sender |

## Modified Files for Phase 1

| File | Changes |
|------|---------|
| `routes.yaml` | Add `return:` and `advisor:` blocks |
| `Config.php` | Parse new YAML sections, calendar integration |
| `collector.php` | Calendar check before collection, weather data columns |
| `schema.php` | New columns: `weather_*`, `is_holiday_adjacent`, `advisor_state` table |
| `AlertManager.php` | New message templates for advisor stages and digest |
| `api.php` | New endpoints: `advisor_status`, `calendar`, `predictions` |
| `dashboard.html` | New tab: "Advisor" showing today's departure recommendations |
