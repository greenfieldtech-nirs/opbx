# Auto Dialer Real-Time Monitor — Specification

## 1. Overview

The Real-Time Monitor is a command-center page within the Auto Dialer module that gives
operators a bird's-eye view of **all active campaigns** and allows drill-down into any
individual campaign for detailed metrics and time-series data.

### 1.1 Design Goals

| # | Goal |
|---|------|
| 1 | Show aggregated health of every running campaign at a glance |
| 2 | Provide per-campaign drill-down with richer metrics and a rolling activity chart |
| 3 | Allow the operator to **take action** (pause / resume / force-pause campaigns) directly from the monitor |
| 4 | Let the user choose the refresh cadence (manual → 10 s → 20 s → 30 s → 40 s → 50 s → 60 s) |
| 5 | Reuse existing infrastructure (HTTP polling via TanStack Query, existing API endpoints where possible) |

### 1.2 User Roles

| Role | Access |
|------|--------|
| Owner | Full access, all actions |
| PBX Admin | Full access, all actions |
| PBX User | No access (hidden from sidebar) |
| Reporter | No access (hidden from sidebar) |

This matches the existing `AutoDialerCampaignPolicy` and the sidebar configuration
(`roles: ['owner', 'pbx_admin']`).

---

## 2. Information Architecture

The page has **two views** that the user toggles between:

```
┌──────────────────────────────────────────────────────────────────────┐
│  Real-Time Monitor                              Refresh: [10s ▾]   │
│                                                                      │
│  ┌─── All Campaigns (bird's-eye) ───────────────────────────────┐   │
│  │  Summary Cards  (total active | total calls now | total CAC  │   │
│  │                  utilization | worker health)                 │   │
│  │                                                               │   │
│  │  Campaign Table / Card Grid                                   │   │
│  │  ┌──────────────────────────────────────────────────────────┐ │   │
│  │  │ Campaign A   Active  ██████░░░░ 62%  4/6 CAC  [Pause]  │ │   │
│  │  │ Campaign B   Active  ████░░░░░░ 38%  2/10 CAC [Pause]  │ │   │
│  │  │ Campaign C   Paused  █████████░ 91%  0/4 CAC  [Resume] │ │   │
│  │  └──────────────────────────────────────────────────────────┘ │   │
│  └───────────────────────────────────────────────────────────────┘   │
│                                                                      │
│  ── or (after clicking a campaign row) ──                           │
│                                                                      │
│  ┌─── Campaign Drill-Down ──────────────────────────────────────┐   │
│  │  [← Back to All]   Campaign A                    [Pause]    │   │
│  │                                                               │   │
│  │  KPI Cards  (active calls | CAC util. | calls/min |          │   │
│  │              answered rate | avg duration | rate-limit status)│   │
│  │                                                               │   │
│  │  Disposition Breakdown        Rolling Activity Chart          │   │
│  │  ┌──────────────────┐         ┌──────────────────────────┐   │   │
│  │  │ Answered   128   │         │  calls/min over last 30m │   │   │
│  │  │ Busy        34   │         │        ╱╲    ╱╲          │   │   │
│  │  │ No-Answer   21   │         │  ╱╲╱╲╱  ╲╱╲╱  ╲╱╲      │   │   │
│  │  │ Failed       7   │         │                          │   │   │
│  │  │ Cancelled    3   │         └──────────────────────────┘   │   │
│  │  └──────────────────┘                                        │   │
│  │                                                               │   │
│  │  Progress & Estimated Completion                              │   │
│  │  ██████████████████████████░░░░░░░░░  68%  ETA: ~42 min     │   │
│  └───────────────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────────────┘
```

---

## 3. Bird's-Eye View (All Campaigns)

### 3.1 Global Summary Cards

Four cards across the top. Values are aggregated from the API response.

| Card | Value | Source |
|------|-------|--------|
| **Active Campaigns** | Count of campaigns with `status = active` | Campaign list |
| **Total Active Calls** | Sum of `active_calls` across all active campaigns | Concurrency endpoint |
| **Overall CAC Utilization** | `sum(active_calls) / sum(cac_limit)` as percentage | Concurrency endpoint |
| **Worker Health** | `healthy` / `degraded` / `offline` | Dialer worker health endpoint |

### 3.2 Campaign Rows

Each active or paused campaign renders as a row (or card in mobile view) containing:

| Column | Content |
|--------|---------|
| **Name** | Campaign name (clickable → drill-down) |
| **Status** | Badge: Active (green) / Paused (yellow) / Paused – Rate Limited (orange) |
| **Progress** | Progress bar with percentage label |
| **Active Calls** | `{active_calls} / {cac_limit}` with a mini utilization bar |
| **Calls / min** | Computed from `completed_calls` delta between polls |
| **Dispositions (mini)** | Compact inline: ✓ {completed}  ✗ {failed}  ⏳ {pending} |
| **Rate Limit** | Icon: 🟢 OK / 🔴 Throttled (with resume-at tooltip) |
| **Actions** | Primary: Pause / Resume button. Secondary: overflow menu with "Archive" |

### 3.3 Campaigns Included

The monitor shows campaigns with status **active** or **paused**. Draft, completed, and
archived campaigns are excluded. A small note at the bottom shows: "Showing {n} active
and paused campaigns. View all campaigns →" linking to the Campaign Manager page.

### 3.4 Empty State

When no campaigns are active or paused:

```
[Radio icon - h-12 w-12]
No active campaigns
All campaigns are either in draft, completed, or archived status.
[Go to Campaign Manager]
```

---

## 4. Campaign Drill-Down View

Accessed by clicking a campaign row. Shows a back button to return to the bird's-eye view.

### 4.1 KPI Cards (top row)

Six cards in a 3×2 grid (desktop) or stacked (mobile):

| Card | Value | Calculation |
|------|-------|-------------|
| **Active Calls** | `{active_calls} / {cac_limit}` | From concurrency endpoint |
| **CAC Utilization** | Percentage with color coding: <50% green, 50–80% yellow, >80% red | `active_calls / cac_limit * 100` |
| **Calls per Minute** | Rolling average | Client-side: delta of `completed_calls + failed_calls` between last two polls, normalized to 60 s |
| **Answer Rate** | Percentage | `completed_calls / (completed_calls + failed_calls) * 100` |
| **Avg Call Duration** | Formatted as `Mm Ss` | Requires new field from backend (see §6) |
| **Rate-Limit Status** | OK / Throttled / Resumes at {time} | From `rate_limit_status` in concurrency response |

### 4.2 Disposition Breakdown

A vertical or horizontal bar chart showing counts for each disposition:

| Disposition | Color |
|-------------|-------|
| Completed / Answered | Green |
| Busy | Yellow |
| No-Answer | Orange |
| Failed | Red |
| Cancelled | Gray |
| Pending (remaining) | Light gray / muted |

Implementation: Use simple colored `<div>` bars (no charting library required). Widths
proportional to counts.

### 4.3 Rolling Activity Chart (Time-Series)

A simple sparkline/area chart showing **calls completed per minute** over the last
30 minutes. This is computed **client-side** by tracking the `completed_calls` counter
at each poll interval and computing deltas.

- X-axis: time (last 30 min in 1-minute buckets)
- Y-axis: calls completed in that minute
- The chart accumulates data while the page is open; it starts empty and fills in over time
- A note below the chart: "Chart data accumulates while this page is open"

Implementation: Use a simple SVG polyline or the lightweight `recharts` library
(already a transitive dependency of shadcn). If not available, a pure CSS/SVG
sparkline is acceptable.

### 4.4 Progress & ETA

- A full-width progress bar showing campaign completion percentage
- ETA calculation (client-side):
  ```
  remaining = pending_calls
  rate = calls_per_minute (from §4.1)
  eta_minutes = remaining / rate
  ```
- Display: `{progress}% complete — ~{eta} remaining` (or "Calculating..." if insufficient data)

### 4.5 Actions

At the top of the drill-down, next to the campaign name and status badge:

| Action | Condition | Button Style |
|--------|-----------|-------------|
| Pause | Campaign is Active | Outline / warning |
| Resume | Campaign is Paused | Primary / default |
| Archive | Campaign is Paused or Completed | Destructive / outline (in overflow menu) |

Each action triggers a confirmation dialog (matching the pattern in
`AutoDialerCampaigns.tsx` and `AutoDialerCampaignDetail.tsx`).

---

## 5. Refresh Strategy

### 5.1 User-Selectable Refresh Interval

A dropdown in the page header allows the user to select the refresh interval:

| Option | Value |
|--------|-------|
| Manual | `0` (no auto-refresh; a "Refresh" button appears) |
| Every 10s | `10000` |
| Every 20s | `20000` |
| Every 30s | `30000` |
| Every 40s | `40000` |
| Every 50s | `50000` |
| Every 60s | `60000` |

Default: **10 seconds**.

The selected interval is persisted in `localStorage` so it survives page reloads.

### 5.2 Implementation

Use TanStack Query's `refetchInterval` option, set dynamically based on the dropdown
selection. When set to `0`, automatic refetch is disabled and a manual "Refresh" icon
button is shown (matching the pattern in `LiveCalls.tsx`).

### 5.3 Data Fetching

All data is fetched via two API calls per tick, fired in parallel:

1. **Campaign list** — `GET /v1/auto-dialer-campaigns?status=active` + a second call
   with `?status=paused` (or a single call returning both — see §6)
2. **Concurrency per campaign** — `GET /v1/auto-dialer-campaigns/{id}/concurrency`
   for each active campaign

Additionally, for the bird's-eye view:

3. **Worker health** — `GET /v1/dialer/worker/health` (proxied through a new
   user-facing endpoint — see §6)

For the drill-down view, no additional endpoints are needed; the concurrency endpoint
already returns the necessary data.

---

## 6. Backend Changes Required

### 6.1 New API Endpoint: Monitor Summary

To avoid N+1 API calls (one concurrency request per campaign), introduce a single
aggregated endpoint:

```
GET /v1/auto-dialer-campaigns/monitor/summary
```

**Response:**

```json
{
  "data": {
    "campaigns": [
      {
        "id": 1,
        "name": "Spring Outreach",
        "status": "active",
        "progress_percentage": 62,
        "total_destinations": 5000,
        "completed_calls": 3100,
        "failed_calls": 450,
        "pending_calls": 1450,
        "concurrent_active_calls": 6,
        "active_calls": 4,
        "cac_utilization": 66.7,
        "rate_limit_status": {
          "is_rate_limited": false,
          "pause_reason": null,
          "resumes_at": null
        },
        "caller_id": "+14155551212",
        "routing_destination_label": "AI Assistant",
        "start_date": "2026-04-01",
        "end_date": "2026-04-30"
      }
    ],
    "totals": {
      "active_campaigns": 2,
      "paused_campaigns": 1,
      "total_active_calls": 6,
      "total_cac_capacity": 20,
      "overall_utilization": 30.0
    },
    "worker_health": {
      "status": "healthy",
      "active_campaigns": 2,
      "active_calls": 6,
      "queue_depth": 42
    }
  }
}
```

**Implementation notes:**

- Added to `AutoDialerCampaignController` (or a new `AutoDialerMonitorController`)
- Queries campaigns with status `active` or `paused`
- For each campaign, reads the Redis CAC counter (`dialer:cac:{id}:active`) to get
  `active_calls` — same logic as the existing `concurrency()` method
- Calls the dialer worker health endpoint server-side (internal Docker network)
  to get worker health — avoids exposing the worker health endpoint to the frontend
- Scoped by `organization_id` (multi-tenant safe)
- Authorized for Owner and PBX Admin roles only

### 6.2 New API Endpoint: Campaign Monitor Detail

For the drill-down view, extend the existing concurrency endpoint with disposition
breakdown and average duration:

```
GET /v1/auto-dialer-campaigns/{campaign}/monitor/detail
```

**Response:**

```json
{
  "data": {
    "campaign": {
      "id": 1,
      "name": "Spring Outreach",
      "status": "active",
      "concurrent_active_calls": 6,
      "active_calls": 4,
      "cac_utilization": 66.7
    },
    "statistics": {
      "total_destinations": 5000,
      "completed_calls": 3100,
      "failed_calls": 450,
      "pending_calls": 1450,
      "progress_percentage": 62,
      "avg_duration_seconds": 145,
      "avg_billsec_seconds": 132
    },
    "dispositions": {
      "answered": 2850,
      "completed": 250,
      "busy": 120,
      "no_answer": 180,
      "failed": 100,
      "cancelled": 50,
      "congestion": 0
    },
    "rate_limit_status": {
      "is_rate_limited": false,
      "pause_reason": null,
      "resumes_at": null,
      "can_resume_now": true
    }
  }
}
```

**Implementation notes:**

- Dispositions are aggregated from `auto_dialer_call_sessions` table grouped by
  `disposition` column
- `avg_duration_seconds` is computed from `auto_dialer_call_sessions` WHERE
  `status = 'completed'` AND `campaign_id = ?`
- Cached in Redis for 10 seconds to avoid heavy DB queries on rapid polling

### 6.3 Route Registration

```php
// routes/api.php — inside the authenticated, tenant-scoped group
Route::get('auto-dialer-campaigns/monitor/summary', [AutoDialerCampaignController::class, 'monitorSummary'])
    ->name('auto-dialer-campaigns.monitor.summary');
Route::get('auto-dialer-campaigns/{campaign}/monitor/detail', [AutoDialerCampaignController::class, 'monitorDetail'])
    ->name('auto-dialer-campaigns.monitor.detail');
```

**Important:** These routes must be registered **before** the `apiResource` route for
`auto-dialer-campaigns` to avoid `monitor` being interpreted as a `{campaign}` parameter.

---

## 7. Frontend Implementation

### 7.1 New Files

| File | Purpose |
|------|---------|
| `frontend/src/pages/AutoDialerMonitor.tsx` | Main monitor page component |
| `frontend/src/services/autoDialerMonitorApi.ts` | API client for monitor endpoints |
| `frontend/src/hooks/useAutoDialerMonitor.ts` | TanStack Query hooks with configurable refetchInterval |

### 7.2 Component Structure

```
AutoDialerMonitor
├── MonitorHeader (title, breadcrumb, refresh selector, manual refresh button)
├── BirdEyeView (default view)
│   ├── GlobalSummaryCards (4 cards)
│   └── CampaignGrid
│       ├── CampaignMonitorRow (for each campaign)
│       │   ├── StatusBadge
│       │   ├── ProgressBar (mini)
│       │   ├── CACUtilizationBar (mini)
│       │   ├── DispositionSummary (inline)
│       │   └── ActionButtons (pause/resume)
│       └── EmptyState (when no active/paused campaigns)
├── CampaignDrillDown (shown when a campaign is selected)
│   ├── DrillDownHeader (back button, name, status, actions)
│   ├── KPICards (6 cards)
│   ├── DispositionBreakdown (bar chart)
│   ├── ActivityChart (rolling sparkline)
│   └── ProgressETA (full-width progress bar with ETA)
└── ConfirmActionDialog (pause/resume/archive confirmation)
```

### 7.3 State Management

```typescript
// Page-level state
const [selectedCampaignId, setSelectedCampaignId] = useState<string | null>(null);
const [refreshInterval, setRefreshInterval] = useState<number>(() => {
  return parseInt(localStorage.getItem('monitor-refresh-interval') || '10000', 10);
});

// For activity chart (client-side time-series accumulation)
const [activityHistory, setActivityHistory] = useState<Map<string, DataPoint[]>>(new Map());
// On each poll, compute delta from previous snapshot and append to history

// For calls-per-minute (client-side)
const [previousSnapshot, setPreviousSnapshot] = useState<Map<string, number>>(new Map());
```

### 7.4 Refresh Interval Selector

```tsx
<Select value={String(refreshInterval)} onValueChange={(v) => {
  const interval = parseInt(v, 10);
  setRefreshInterval(interval);
  localStorage.setItem('monitor-refresh-interval', v);
}}>
  <SelectTrigger className="w-[160px]">
    <SelectValue />
  </SelectTrigger>
  <SelectContent>
    <SelectItem value="0">Manual</SelectItem>
    <SelectItem value="10000">Every 10s</SelectItem>
    <SelectItem value="20000">Every 20s</SelectItem>
    <SelectItem value="30000">Every 30s</SelectItem>
    <SelectItem value="40000">Every 40s</SelectItem>
    <SelectItem value="50000">Every 50s</SelectItem>
    <SelectItem value="60000">Every 60s</SelectItem>
  </SelectContent>
</Select>
```

### 7.5 Router Update

```tsx
// frontend/src/router.tsx — replace the placeholder
{
  path: 'auto-dialer/monitor',
  element: <AutoDialerMonitor />,
}
```

---

## 8. UI Design Details

### 8.1 Color Palette (consistent with existing app)

| Element | Color |
|---------|-------|
| Active status | `bg-green-100 text-green-800` |
| Paused status | `bg-yellow-100 text-yellow-800` |
| Rate-limited status | `bg-orange-100 text-orange-800` |
| CAC utilization < 50% | Green bar |
| CAC utilization 50–80% | Yellow bar |
| CAC utilization > 80% | Red bar |
| Worker healthy | Green dot |
| Worker degraded | Yellow dot |
| Worker offline | Red dot |

### 8.2 Responsive Behavior

- **Desktop (≥1024px):** Summary cards in a row of 4; campaign grid as table rows;
  drill-down KPI cards in 3×2 grid; chart and dispositions side-by-side
- **Tablet (768–1023px):** Summary cards 2×2; campaign grid as cards; drill-down
  KPIs in 2×3; chart and dispositions stacked
- **Mobile (<768px):** Everything stacked vertically

### 8.3 Loading State

While data is loading (first fetch), show skeleton placeholders matching the card and
table layout — consistent with the pattern in `AutoDialerCampaigns.tsx`.

### 8.4 Error State

On API error, show an error card with retry button — consistent with the error state
pattern in `AutoDialerCampaigns.tsx`.

---

## 9. Worker Health Determination

The worker health status shown in the monitor is determined by the backend, which
proxies to the Go worker's `/health` endpoint:

| Condition | Status | Display |
|-----------|--------|---------|
| Worker responds, `active_campaigns > 0` | `healthy` | 🟢 Healthy |
| Worker responds, `active_campaigns = 0` | `healthy` | 🟢 Idle |
| Worker responds, but `queue_depth > 1000` | `degraded` | 🟡 Degraded |
| Worker does not respond within 5s | `offline` | 🔴 Offline |
| Worker health endpoint not configured | `unknown` | ⚪ Unknown |

The backend makes this determination when building the monitor summary response.
The frontend simply renders the status.

---

## 10. Calls-Per-Minute Calculation (Client-Side)

Since the backend returns absolute counters (`completed_calls`, `failed_calls`), the
frontend computes velocity client-side:

```typescript
function computeCallsPerMinute(
  currentTotal: number,
  previousTotal: number,
  intervalMs: number
): number {
  if (intervalMs <= 0) return 0;
  const delta = currentTotal - previousTotal;
  if (delta <= 0) return 0;
  const intervalMinutes = intervalMs / 60000;
  return Math.round(delta / intervalMinutes);
}
```

The `previousTotal` is captured on each successful poll. On the first poll, CPM shows
"—" (no data yet).

---

## 11. Activity Chart Data Accumulation

The rolling activity chart in the drill-down view works as follows:

1. On each poll, record: `{ timestamp, completed_calls, failed_calls }`
2. Compute delta from previous snapshot → `calls_this_interval`
3. Bucket into 1-minute windows
4. Keep the most recent 30 minutes of data
5. Render as an SVG polyline (or lightweight chart component)

Data is stored in React state (lost on page navigation). A note below the chart
states: *"Chart data accumulates while this page is open."*

---

## 12. Action Flows

### 12.1 Pause Campaign

1. User clicks "Pause" button
2. Confirmation dialog: "Pause campaign '{name}'? Active calls will continue but no new calls will be initiated."
3. On confirm: `PATCH /v1/auto-dialer-campaigns/{id}/pause`
4. On success: toast "Campaign paused" + invalidate monitor queries
5. Campaign row updates to show Paused status

### 12.2 Resume Campaign

1. User clicks "Resume" button
2. Confirmation dialog: "Resume campaign '{name}'? The dialer will begin initiating new calls."
3. On confirm: `PATCH /v1/auto-dialer-campaigns/{id}/resume`
4. On success: toast "Campaign resumed" + invalidate monitor queries

### 12.3 Archive Campaign (overflow menu, drill-down only)

1. User clicks "Archive" in overflow menu
2. Confirmation dialog: "Archive campaign '{name}'? This action cannot be undone."
3. On confirm: `PATCH /v1/auto-dialer-campaigns/{id}/archive`
4. On success: toast "Campaign archived" + navigate back to bird's-eye view
5. Campaign disappears from monitor (no longer active/paused)

---

## 13. Out of Scope (for v1 of this page)

- WebSocket push-based updates (polling is sufficient; can be added later)
- Individual active call rows (aggregates only — per requirement)
- Sound / audio alerts on events
- Export / download of monitor data
- Historical monitor data beyond the client-side 30-minute window

---

## 14. Test Plan

### 14.1 Backend Tests

| Test | Assertion |
|------|-----------|
| Monitor summary returns only active+paused campaigns | Filter verification |
| Monitor summary is tenant-scoped | Cross-org isolation |
| Monitor summary includes correct active_calls from Redis | Redis integration |
| Monitor detail returns correct disposition breakdown | Aggregation accuracy |
| Monitor detail caches for 10s | Cache hit/miss verification |
| Unauthorized roles get 403 | RBAC enforcement |

### 14.2 Frontend Tests (manual / visual verification)

| Scenario | Expected |
|----------|----------|
| No active campaigns | Empty state displayed |
| 3 active, 1 paused campaign | All 4 shown; totals correct |
| Click campaign row | Drill-down view shown |
| Click "Back" in drill-down | Returns to bird's-eye |
| Change refresh interval | Polling frequency changes; persists on reload |
| Pause campaign from monitor | Status updates; row changes to Paused |
| Rate-limited campaign | Orange badge; resumes-at tooltip |
| Worker offline | Red dot in summary card |

---

## 15. Implementation Order

| Step | Description | Agent |
|------|-------------|-------|
| 1 | Create `monitorSummary` and `monitorDetail` backend endpoints | php-pro |
| 2 | Register routes (before apiResource) | php-pro |
| 3 | Create `autoDialerMonitorApi.ts` API service | frontend-developer |
| 4 | Create `useAutoDialerMonitor.ts` hooks | frontend-developer |
| 5 | Build `AutoDialerMonitor.tsx` — bird's-eye view | frontend-developer |
| 6 | Build drill-down view within the same component | frontend-developer |
| 7 | Wire up action buttons (pause/resume/archive) | frontend-developer |
| 8 | Update router to replace placeholder | frontend-developer |
| 9 | Write backend feature tests | php-pro |
| 10 | Run regression tests | project-regression-tester |

---

## 16. File Inventory (Expected New/Modified Files)

### New Files
- `app/Http/Controllers/AutoDialerMonitorController.php` (or methods in existing controller)
- `frontend/src/pages/AutoDialerMonitor.tsx`
- `frontend/src/services/autoDialerMonitorApi.ts`
- `frontend/src/hooks/useAutoDialerMonitor.ts`

### Modified Files
- `routes/api.php` — add monitor routes
- `frontend/src/router.tsx` — replace placeholder import
- `memory/auto-dialer-campaigns.md` — update with new endpoints
