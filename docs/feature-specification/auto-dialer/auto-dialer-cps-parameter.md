# Auto Dialer CPS (Calls Per Second) Parameter — Specification

## 1. Overview

Introduce a **CPS (Calls Per Second)** parameter to auto-dialer campaigns that controls
the call initiation rate, independent of the existing CAC (Concurrent Active Calls) limit.

### Current behavior
- CAC controls both the concurrency ceiling AND the initiation rate (`60/CAC` seconds between calls)
- The batch size per poll cycle equals CAC

### New behavior
- **CAC** = maximum concurrent active calls (ceiling). Integer 1–50.
- **CPS** = call initiation rate (1–5 calls per second). Default: 1.
- The Go worker initiates calls at CPS rate until CAC is reached
- Batch size per poll cycle = `CPS × poll_interval` (capped at CAC)
- The `MinInterval` time limiter is derived from CPS, not CAC: `1000/CPS` milliseconds

## 2. Changes Required

### 2.1 Database Migration

Add `calls_per_second` column to `auto_dialer_campaigns`:

```php
Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
    $table->unsignedTinyInteger('calls_per_second')->default(1)->after('concurrent_active_calls');
});
```

### 2.2 Backend (Laravel)

**Model: `AutoDialerCampaign`**
- Add `calls_per_second` to `$fillable`
- Add cast: `'calls_per_second' => 'integer'`

**Validation: `CreateCampaignRequest` + `UpdateCampaignRequest`**
- Change `concurrent_active_calls` from `'in:2,3,4,6,10,15,20'` to `'integer', 'min:1', 'max:50'`
- Add `'calls_per_second' => ['sometimes', 'integer', 'min:1', 'max:5']`

**Resource: `AutoDialerCampaignResource`**
- Add `'calls_per_second' => $this->calls_per_second`

**Worker API: `DialerWorkerController`**
- The `getActiveCampaigns` response already includes all campaign fields — `calls_per_second`
  will be included automatically via the resource/model

### 2.3 Go Worker

**Models: `models.go`**
- Add `CPS int \`json:"calls_per_second"\`` to `Campaign` struct

**Rate Limiter: `limiter/cac.go`**
- `RegisterCampaign(campaignID int64, cac int, cps int)`
- `MinInterval` = `time.Duration(1000/cps) * time.Millisecond` (derived from CPS, not CAC)
- `CampaignLimiter` struct gains `CPS int` field

**Main Loop: `cmd/worker/main.go`**
- Batch size: `batchSize = campaign.CPS * pollIntervalSeconds` (capped at `campaign.CAC`)
- `GetPendingDestinations(ctx, campaign.ID, batchSize)` instead of `campaign.CAC`
- `RegisterCampaign(campaign.ID, campaign.CAC, campaign.CPS)`

### 2.4 Frontend

**Form Schema (`AutoDialerCampaignForm.tsx`)**
- Change `concurrent_active_calls` from select (fixed values) to number input, min 1, max 50, default 1
- Add `calls_per_second`: select 1–5, default 1
- Layout: 3-column grid → CAC | CPS | Max Dial Attempts

**API types (`autoDialerCampaignsApi.ts`)**
- Add `calls_per_second: number` to `AutoDialerCampaign` and `CreateCampaignRequest`

**Monitor (`AutoDialerMonitor.tsx`)**
- No changes needed — CPS is a configuration parameter, not a live metric

## 3. CPS/CAC Interaction Table

| CPS | MinInterval | Calls per 10s poll | CAC ceiling |
|-----|-------------|-------------------|-------------|
| 1   | 1000ms      | 10                | up to 50    |
| 2   | 500ms       | 20                | up to 50    |
| 3   | 333ms       | 30                | up to 50    |
| 4   | 250ms       | 40                | up to 50    |
| 5   | 200ms       | 50                | up to 50    |

The actual batch size is `min(CPS × 10, CAC)`. If a campaign has CPS=5 and CAC=10,
the worker will fetch 10 destinations (capped at CAC) and initiate them at 200ms intervals.

## 4. Implementation Order

| Step | Description | Layer |
|------|-------------|-------|
| 1 | Database migration | Backend |
| 2 | Update model, validation, resource | Backend |
| 3 | Update Go worker models + rate limiter + main loop | Go |
| 4 | Update campaign form (CAC input + CPS select) | Frontend |
| 5 | Update API types | Frontend |
| 6 | Test | All |
