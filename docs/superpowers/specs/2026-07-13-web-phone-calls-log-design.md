# Web Phone "Calls Log" Design Specification

> **Feature:** Add a tabbed "Calls Log" view to the Web Phone showing the last
> 50 outbound calls placed by the signed-in user's own extension, with tap-to-redial.

## Summary

The Web Phone currently shows only a dialer. This feature adds a second view,
"Calls Log", selectable via an iOS/Samsung-style tab bar pinned to the bottom of
the Web Phone card. The Calls Log lists the last 50 calls placed by the
signed-in user's own extension (server-side CDR data), excluding coaching
(spy/whisper/barge) calls. Tapping an entry switches to the Dialer and
immediately redials that number.

## User Story

> As an OpBX user with the Web Phone open, I want to see my recent outbound
> calls and redial any of them in one tap, without leaving the Web Phone.

## Data Source

Server-side Call Detail Records (`call_detail_records`), filtered to the calls
**placed by the signed-in user's own USER extension** (`from` exact match).
Rationale: CDRs survive reloads and devices and carry accurate duration and
disposition. The literal "made by this Web Phone" (per-browser local log) was
rejected in favor of server accuracy and cross-device consistency.

## Architecture

```
WebPhoneCallsLogController (new, backend)
  └── GET /api/v1/webphone/calls-log
        └── WebPhone.tsx  (parent — owns SIP + activeTab + redial)
              ├── WebPhoneTabs   (new — bottom tab bar: Dialer | Calls Log)
              ├── DialerView     (new — extracted idle-dialer JSX)
              └── CallsLogView   (new — fetches + lists CDRs, tap-to-redial)
```

All SIP / call-lifecycle logic stays in `WebPhone.tsx`. The two views are
presentational; the parent owns state and passes handlers down.

## Backend

### Endpoint

`GET /v1/webphone/calls-log`

- **Controller:** new `WebPhoneCallsLogController` (or a `callsLog` method on the
  existing `WebPhoneConfigController` — implementer's choice; a dedicated
  controller is preferred for separation).
- **Route:** registered alongside the existing `webphone/config` route in
  `routes/api.php` (same middleware group), name `webphone.calls-log`.
- **Auth/scope:** authenticated user; organization-scoped via `OrganizationScope`
  on `CallDetailRecord`. Always scoped to the **caller's own** extension — no
  client-supplied extension filter.

### Logic

1. Resolve the signed-in user's USER-type extension (reuse the lookup already in
   `WebPhoneConfigController::config`: `Extension::where('user_id', $user->id)
   ->where('type', ExtensionType::USER)->where('organization_id', ...)`).
2. If no extension → `404` with message `"No extension is assigned to this user."`
   (mirrors the config endpoint).
3. Query:
   ```php
   CallDetailRecord::forOrganization($user->organization_id)
       ->where('from', $extension->extension_number)   // EXACT match — "placed by"
       ->where('to', 'not like', 'spy\_%')
       ->where('to', 'not like', 'barge\_%')
       ->where('to', 'not like', 'whisper\_%')
       ->orderByDesc('session_timestamp')
       ->limit(50)
       ->get();
   ```
   The coaching exclusion uses the sentinel prefixes defined in
   `CoachRoutingService` (`spy_`/`barge_`/`whisper_{party}_` followed by a hex
   token). Prefix `LIKE` exclusion is sufficient and simple; the underscore is
   escaped so it is treated literally.
4. Return via a resource shaped for the Web Phone (a subset of
   `CallDetailRecordResource`):
   ```json
   {
     "data": [
       {
         "to": "12125551234",
         "session_timestamp": "2026-07-13T10:22:31Z",
         "duration": 154,
         "duration_formatted": "2:34",
         "disposition": "ANSWERED"
       }
     ]
   }
   ```
   Empty history returns `200` with `"data": []`.

### Why exact match (not the existing endpoint)

The existing `GET /call-detail-records` filters `from` with partial `LIKE %..%`,
so extension `100` would match calls from `1001`, `2100`, etc. The Web Phone
needs calls placed by exactly the caller's extension, so a dedicated endpoint
with an exact `from` match is used. It also avoids leaking a per-user concern
into the shared CDR endpoint and never trusts a client-supplied extension.

## Frontend

### `WebPhone.tsx` (parent — modified)

- Add `activeTab: 'dialer' | 'log'` state (default `'dialer'`).
- Add `pendingRedialRef` + an effect that, when set and
  `state === 'ready' && callState === 'idle'`, sets the number and calls
  `handleCall()` — mirroring the existing `pendingCoachRef` + auto-dial effect
  (`WebPhone.tsx:116`, `:399-404`). **Do not** call `handleCall()` synchronously
  after `setNumber` (stale-state bug).
- `inActiveCall` (`WebPhone.tsx:122`) forces `activeTab = 'dialer'` and disables
  tab switching while a call is ringing/connected (consistent with the existing
  collapse lock).
- Inside the `state === 'ready'` block: render `<WebPhoneTabs>` as a fixed footer
  (between the scroll body end at `:784` and the card end at `:785`), and render
  `DialerView` or `CallsLogView` based on `activeTab`. The incoming-call and
  connected sub-views are unchanged and always take precedence over the tabbed
  idle content.

### `DialerView` (new — presentational)

The existing idle-dialer JSX (`WebPhone.tsx:707-779`) moved verbatim: number
display / editable input, dial pad, Call / Hangup buttons.
**Props:** `number`, `status`, `coachLabel`, `callState`, `onAppendDigit`,
`onBackspace`, `onChangeNumber`, `onCall`, `onHangup`.

### `CallsLogView` (new)

- Fetches via TanStack Query: key `['webphone-calls-log']`,
  `enabled: activeTab === 'log' && state === 'ready'`, `retry: false`, short
  `staleTime` (or refetch on mount) so a just-completed call appears when the tab
  is opened.
- States: loading (spinner), error (message; re-opening the tab retries), empty
  (`data: []` → "No recent calls"), populated (list).
- Row: dialed `to` number (primary), timestamp, `duration_formatted`, and a
  disposition indicator (answered / no-answer / busy / failed derived from
  `disposition`). Tapping the row calls `onRedial(to)`.
- **Props:** `onRedial(number: string)`.

### `WebPhoneTabs` (new)

- Fixed footer bar, iOS/Samsung-style, two segments with icons: **Dialer** and
  **Calls Log**. Pinned to the bottom of the card, outside the scroll region so
  it stays visible.
- **Props:** `activeTab`, `onChange(tab)`, `disabled` (true during active call).

### Service & Types

- `frontend/src/services/webPhone.service.ts` — add
  `getWebPhoneCallsLog(): Promise<{ data: WebPhoneCallLogEntry[] }>` hitting
  `/webphone/calls-log`.
- `frontend/src/types/webPhone.types.ts` — add:
  ```ts
  export interface WebPhoneCallLogEntry {
    to: string;
    session_timestamp: string;
    duration: number;
    duration_formatted: string;
    disposition: string;
  }
  ```

## Data Flow (redial)

1. User on Calls Log tab taps an entry.
2. `CallsLogView` calls `onRedial(to)`.
3. Parent sets `pendingRedialRef = to` and `activeTab = 'dialer'`.
4. Redial effect fires on next render (guarded by
   `state === 'ready' && callState === 'idle'`): sets number, calls `handleCall`.
5. Dialer view shows the active call.

## Error Handling

- **Backend:** no extension → 404; org-scoped so cross-tenant CDRs never leak;
  coaching sentinels excluded server-side (can't be listed or redialed); empty
  history → `200 { data: [] }`.
- **Frontend:** `retry: false`; distinct loading / error / empty / populated
  states; a mid-session 404 (extension removed) renders the error state, not a
  crash.
- **Redial guard:** fires only when ready and idle (same guard as coach
  auto-dial). Tabs are locked during active calls, so the log isn't reachable
  mid-call.

## Testing

### Backend — `WebPhoneCallsLogControllerTest` (MySQL, per project policy)

- User with extension → returns only CDRs where `from` equals their **exact**
  extension; assert a `1001` extension does **not** match `10010`'s calls.
- Excludes `spy_` / `barge_` / `whisper_` sentinel `to` values.
- Caps at 50, ordered `session_timestamp desc`.
- Organization isolation: another org's CDRs never appear.
- No extension → 404.
- Empty history → `200` with `data: []`.

### Frontend

- `tsc --noEmit` passes with the new components, service, and types.
- If a component test harness exists, a light `CallsLogView` render check
  (loading / empty / populated / redial callback). Otherwise rely on type-check.
- Note: `npm run lint` is currently broken repo-wide (missing ESLint config) —
  pre-existing and unrelated to this feature.

## Out of Scope

- Tagging CDRs as Web-Phone-originated (would show ALL outbound calls from the
  extension, which is accepted).
- Inbound call history.
- Per-browser local persistence.
- Any WebPhone refactor beyond extracting `DialerView`, `CallsLogView`, and
  `WebPhoneTabs`.

## References

- WebPhone component (single file): `frontend/src/components/WebPhone/WebPhone.tsx`
  - idle dialer JSX to extract: `:707-779`
  - tab-bar insertion point: between `:784` and `:785`
  - coach auto-dial pattern to mirror for redial: `:116`, `:399-404`
  - active-call lock: `:122`
- Coach event bus: `frontend/src/components/WebPhone/webPhoneBus.ts`
- Config endpoint (extension lookup + 404 pattern to mirror):
  `app/Http/Controllers/Api/WebPhoneConfigController.php:22-33`
- CDR model / scope: `app/Models/CallDetailRecord.php` (`forOrganization` scope)
- Coaching sentinel patterns: `app/Services/VoiceRouting/CoachRoutingService.php:33-35`
- Existing CDR resource shape: `app/Http/Resources/CallDetailRecordResource.php`
- Existing CDR route group: `routes/api.php:417-423`
