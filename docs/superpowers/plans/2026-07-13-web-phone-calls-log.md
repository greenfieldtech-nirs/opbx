# Web Phone Calls Log Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a tabbed "Calls Log" view to the Web Phone that lists the last 50 outbound calls placed by the signed-in user's own extension and lets the user redial any of them.

**Architecture:** A new backend endpoint `GET /v1/webphone/calls-log` returns the last 50 `CallDetailRecord`s where `from` equals the caller's exact extension, org-scoped, with coaching sentinel destinations excluded. The frontend extracts the dialer JSX into `DialerView`, adds `CallsLogView` and a `WebPhoneTabs` footer, and `WebPhone.tsx` (unchanged SIP logic) owns an `activeTab` state plus a redial effect mirroring the existing coach auto-dial pattern.

**Tech Stack:** Laravel 12 (PHP 8.4), Eloquent, PHPUnit (MySQL), React 18 + TypeScript, TanStack Query, Tailwind, lucide-react.

---

## File Structure

**Backend:**
- Create `app/Http/Controllers/Api/WebPhoneCallsLogController.php` — resolves caller's extension, queries last 50 CDRs, excludes coaching sentinels.
- Modify `routes/api.php` — register `GET webphone/calls-log`.
- Create `tests/Feature/Api/WebPhoneCallsLogControllerTest.php` — endpoint tests.

**Frontend:**
- Modify `frontend/src/types/webPhone.types.ts` — add `WebPhoneCallLogEntry`.
- Modify `frontend/src/services/webPhone.service.ts` — add `getWebPhoneCallsLog()`.
- Create `frontend/src/components/WebPhone/WebPhoneTabs.tsx` — bottom tab bar.
- Create `frontend/src/components/WebPhone/CallsLogView.tsx` — the log list.
- Modify `frontend/src/components/WebPhone/WebPhone.tsx` — add `activeTab` state, redial effect, render tabs + views.

Coaching-sentinel exclusion uses prefix `LIKE` on `to` (`spy_`, `barge_`, `whisper_`), matching the prefixes in `app/Services/VoiceRouting/CoachRoutingService.php:33-35`.

---

## Task 1: Backend endpoint — happy path (owner with extension returns their calls)

**Files:**
- Create: `app/Http/Controllers/Api/WebPhoneCallsLogController.php`
- Modify: `routes/api.php:370-372`
- Test: `tests/Feature/Api/WebPhoneCallsLogControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Api/WebPhoneCallsLogControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\CallDetailRecord;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class WebPhoneCallsLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Test Organization',
            'slug' => 'test-org',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);
    }

    private function createUser(UserRole $role): User
    {
        return User::create([
            'organization_id' => $this->organization->id,
            'name' => $role->name.' User',
            'email' => strtolower($role->name).'@example.com',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function createExtension(User $user, string $number): Extension
    {
        return Extension::create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'extension_number' => $number,
            'password' => 'secret123',
            'type' => ExtensionType::USER,
            'status' => 'active',
        ]);
    }

    private function createCdr(string $from, string $to, string $ts): CallDetailRecord
    {
        return CallDetailRecord::create([
            'organization_id' => $this->organization->id,
            'session_timestamp' => $ts,
            'session_token' => 'tok-'.uniqid(),
            'from' => $from,
            'to' => $to,
            'disposition' => 'ANSWERED',
            'duration' => 154,
            'billsec' => 150,
        ]);
    }

    public function test_returns_calls_placed_by_own_extension(): void
    {
        $owner = $this->createUser(UserRole::OWNER);
        $this->createExtension($owner, '1000');
        $this->createCdr('1000', '12125551234', '2026-07-13 10:00:00');

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/calls-log');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.to', '12125551234')
            ->assertJsonPath('data.0.disposition', 'ANSWERED')
            ->assertJsonPath('data.0.duration', 154);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./run-tests.sh --filter=WebPhoneCallsLogControllerTest`
Expected: FAIL — route `/api/v1/webphone/calls-log` not defined (404 assertion failure).

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/WebPhoneCallsLogController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ExtensionType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Models\CallDetailRecord;
use App\Models\Extension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebPhoneCallsLogController extends Controller
{
    use ApiRequestHandler;

    public function index(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        $extension = Extension::where('user_id', $user->id)
            ->where('type', ExtensionType::USER)
            ->where('organization_id', $user->organization_id)
            ->first();

        if (! $extension) {
            return response()->json([
                'message' => 'No extension is assigned to this user.',
            ], 404);
        }

        $records = CallDetailRecord::forOrganization($user->organization_id)
            ->where('from', $extension->extension_number)
            ->where('to', 'not like', 'spy\_%')
            ->where('to', 'not like', 'barge\_%')
            ->where('to', 'not like', 'whisper\_%')
            ->orderByDesc('session_timestamp')
            ->limit(50)
            ->get();

        $data = $records->map(fn (CallDetailRecord $cdr) => [
            'to' => $cdr->to,
            'session_timestamp' => $cdr->session_timestamp?->toIso8601String(),
            'duration' => (int) $cdr->duration,
            'duration_formatted' => $cdr->formatted_duration,
            'disposition' => $cdr->disposition,
        ])->all();

        return response()->json(['data' => $data]);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, after the existing Web Phone config route (lines 370-372), add the calls-log route. Change:

```php
        // Web Phone config
        Route::get('webphone/config', [WebPhoneConfigController::class, 'config'])
            ->name('webphone.config');
```

to:

```php
        // Web Phone config
        Route::get('webphone/config', [WebPhoneConfigController::class, 'config'])
            ->name('webphone.config');

        // Web Phone calls log
        Route::get('webphone/calls-log', [WebPhoneCallsLogController::class, 'index'])
            ->name('webphone.calls-log');
```

Add the import near the existing `use App\Http\Controllers\Api\WebPhoneConfigController;` (line 42):

```php
use App\Http\Controllers\Api\WebPhoneCallsLogController;
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./run-tests.sh --filter=WebPhoneCallsLogControllerTest`
Expected: PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/WebPhoneCallsLogController.php routes/api.php tests/Feature/Api/WebPhoneCallsLogControllerTest.php
git commit -m "feat: add webphone calls-log endpoint"
```

---

## Task 2: Backend — exact match, coaching exclusion, ordering, cap, isolation, 404, empty

**Files:**
- Test: `tests/Feature/Api/WebPhoneCallsLogControllerTest.php`

- [ ] **Step 1: Add the failing tests**

Append these methods to `WebPhoneCallsLogControllerTest` (before the closing brace):

```php
    public function test_from_is_exact_match_not_partial(): void
    {
        $owner = $this->createUser(UserRole::OWNER);
        $this->createExtension($owner, '1001');
        $this->createCdr('1001', '12125550001', '2026-07-13 10:00:00'); // theirs
        $this->createCdr('10010', '12125550002', '2026-07-13 11:00:00'); // NOT theirs

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/calls-log');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.to', '12125550001');
    }

    public function test_excludes_coaching_sentinel_destinations(): void
    {
        $owner = $this->createUser(UserRole::OWNER);
        $this->createExtension($owner, '1000');
        $this->createCdr('1000', '12125551234', '2026-07-13 10:00:00');
        $this->createCdr('1000', 'spy_deadbeefdeadbeef', '2026-07-13 10:01:00');
        $this->createCdr('1000', 'barge_deadbeefdeadbeef', '2026-07-13 10:02:00');
        $this->createCdr('1000', 'whisper_caller_deadbeefdeadbeef', '2026-07-13 10:03:00');

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/calls-log');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.to', '12125551234');
    }

    public function test_orders_by_timestamp_desc_and_caps_at_50(): void
    {
        $owner = $this->createUser(UserRole::OWNER);
        $this->createExtension($owner, '1000');
        for ($i = 0; $i < 55; $i++) {
            $ts = sprintf('2026-07-13 %02d:%02d:00', intdiv($i, 60), $i % 60);
            $this->createCdr('1000', '1212555'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), $ts);
        }

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/calls-log');

        $response->assertStatus(200)->assertJsonCount(50, 'data');
        // Newest first: i=54 has the latest timestamp.
        $response->assertJsonPath('data.0.to', '12125550054');
    }

    public function test_does_not_return_other_organizations_calls(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'slug' => 'other-org',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $owner = $this->createUser(UserRole::OWNER);
        $this->createExtension($owner, '1000');

        // A CDR in another org with the same 'from' value must not leak.
        CallDetailRecord::create([
            'organization_id' => $otherOrg->id,
            'session_timestamp' => '2026-07-13 10:00:00',
            'session_token' => 'tok-other',
            'from' => '1000',
            'to' => '19998887777',
            'disposition' => 'ANSWERED',
            'duration' => 10,
            'billsec' => 10,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/calls-log');

        $response->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_returns_404_when_user_has_no_extension(): void
    {
        $owner = $this->createUser(UserRole::OWNER);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/calls-log');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'No extension is assigned to this user.');
    }

    public function test_returns_empty_data_when_no_calls(): void
    {
        $owner = $this->createUser(UserRole::OWNER);
        $this->createExtension($owner, '1000');

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/webphone/calls-log');

        $response->assertStatus(200)->assertExactJson(['data' => []]);
    }
```

- [ ] **Step 2: Run tests**

Run: `./run-tests.sh --filter=WebPhoneCallsLogControllerTest`
Expected: PASS (all 7 tests). The controller from Task 1 already implements exact match, coaching exclusion, ordering, cap, org scope (via `forOrganization` + global `OrganizationScope`), 404, and empty-array response — these tests lock that behavior in.

Note on org isolation: `CallDetailRecord` has a global `OrganizationScope`, and `Sanctum::actingAs` sets the tenant context. If `test_does_not_return_other_organizations_calls` unexpectedly fails because the global scope isn't applied in the test context, the explicit `forOrganization($user->organization_id)` filter in the controller still guarantees isolation — the test will pass on that filter alone.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Api/WebPhoneCallsLogControllerTest.php
git commit -m "test: cover webphone calls-log exact match, coaching exclusion, ordering, isolation"
```

---

## Task 3: Frontend types and service

**Files:**
- Modify: `frontend/src/types/webPhone.types.ts`
- Modify: `frontend/src/services/webPhone.service.ts`

- [ ] **Step 1: Add the call-log entry type**

In `frontend/src/types/webPhone.types.ts`, append after the `WebPhoneConfig` interface (end of file):

```ts
export interface WebPhoneCallLogEntry {
  to: string;
  session_timestamp: string;
  duration: number;
  duration_formatted: string;
  disposition: string;
}
```

- [ ] **Step 2: Add the service function**

In `frontend/src/services/webPhone.service.ts`, update the imports and append the new function. Change the import line:

```ts
import type { WebPhoneConfig } from '@/types/webPhone.types';
```

to:

```ts
import type { WebPhoneConfig, WebPhoneCallLogEntry } from '@/types/webPhone.types';
```

Then append at the end of the file:

```ts
export interface WebPhoneCallsLogResponse {
  data: WebPhoneCallLogEntry[];
}

/**
 * Fetch the last 50 outbound calls placed by the signed-in user's extension.
 */
export async function getWebPhoneCallsLog(): Promise<WebPhoneCallsLogResponse> {
  const response = await api.get<WebPhoneCallsLogResponse>('/webphone/calls-log');
  return response.data;
}
```

- [ ] **Step 3: Type-check**

Run: `cd frontend && npm run type-check`
Expected: PASS (no errors).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/types/webPhone.types.ts frontend/src/services/webPhone.service.ts
git commit -m "feat: add webphone calls-log type and service"
```

---

## Task 4: WebPhoneTabs component

**Files:**
- Create: `frontend/src/components/WebPhone/WebPhoneTabs.tsx`

- [ ] **Step 1: Create the component**

Create `frontend/src/components/WebPhone/WebPhoneTabs.tsx`:

```tsx
import { Grid3x3, Clock } from 'lucide-react';

export type WebPhoneTab = 'dialer' | 'log';

interface WebPhoneTabsProps {
  activeTab: WebPhoneTab;
  onChange: (tab: WebPhoneTab) => void;
  disabled?: boolean;
}

const TABS: { id: WebPhoneTab; label: string; Icon: typeof Grid3x3 }[] = [
  { id: 'dialer', label: 'Dialer', Icon: Grid3x3 },
  { id: 'log', label: 'Recents', Icon: Clock },
];

/**
 * iOS/Samsung-style bottom tab bar for the Web Phone. Pinned to the bottom of
 * the card, outside the scrollable body so it stays visible.
 */
export function WebPhoneTabs({ activeTab, onChange, disabled = false }: WebPhoneTabsProps) {
  return (
    <div className="flex items-stretch border-t bg-background">
      {TABS.map(({ id, label, Icon }) => {
        const isActive = activeTab === id;
        return (
          <button
            key={id}
            type="button"
            disabled={disabled}
            onClick={() => onChange(id)}
            className={`flex flex-1 flex-col items-center justify-center gap-0.5 py-2.5 text-[11px] font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed ${
              isActive ? 'text-primary' : 'text-muted-foreground hover:text-foreground'
            }`}
            aria-label={label}
            aria-current={isActive ? 'page' : undefined}
          >
            <Icon className="h-5 w-5" />
            {label}
          </button>
        );
      })}
    </div>
  );
}

export default WebPhoneTabs;
```

- [ ] **Step 2: Type-check**

Run: `cd frontend && npm run type-check`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/WebPhone/WebPhoneTabs.tsx
git commit -m "feat: add WebPhoneTabs bottom tab bar"
```

---

## Task 5: CallsLogView component

**Files:**
- Create: `frontend/src/components/WebPhone/CallsLogView.tsx`

- [ ] **Step 1: Create the component**

Create `frontend/src/components/WebPhone/CallsLogView.tsx`:

```tsx
import { useQuery } from '@tanstack/react-query';
import { Loader2, AlertTriangle, PhoneOutgoing, PhoneMissed } from 'lucide-react';
import { getWebPhoneCallsLog } from '@/services/webPhone.service';
import type { WebPhoneCallLogEntry } from '@/types/webPhone.types';

interface CallsLogViewProps {
  enabled: boolean;
  onRedial: (number: string) => void;
}

function isAnswered(disposition: string): boolean {
  const d = (disposition ?? '').toUpperCase();
  return d === 'ANSWERED' || d === 'CONNECTED';
}

function formatTimestamp(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

/**
 * The Web Phone "Recents" tab: last 50 outbound calls placed by the user's
 * extension. Tapping a row redials that number.
 */
export function CallsLogView({ enabled, onRedial }: CallsLogViewProps) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['webphone-calls-log'],
    queryFn: getWebPhoneCallsLog,
    enabled,
    retry: false,
    staleTime: 0,
  });

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-12">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        <p className="text-sm text-muted-foreground">Loading recent calls...</p>
      </div>
    );
  }

  if (isError) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-12 text-center">
        <AlertTriangle className="h-8 w-8 text-destructive" />
        <p className="text-sm text-muted-foreground">Could not load recent calls.</p>
      </div>
    );
  }

  const entries: WebPhoneCallLogEntry[] = data?.data ?? [];

  if (entries.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-12 text-center">
        <PhoneOutgoing className="h-8 w-8 text-muted-foreground" />
        <p className="text-sm text-muted-foreground">No recent calls</p>
      </div>
    );
  }

  return (
    <div className="flex w-full flex-col">
      {entries.map((entry, idx) => {
        const answered = isAnswered(entry.disposition);
        return (
          <button
            key={`${entry.to}-${entry.session_timestamp}-${idx}`}
            type="button"
            onClick={() => onRedial(entry.to)}
            className="flex items-center gap-3 border-b px-1 py-3 text-left last:border-b-0 hover:bg-muted/50 active:bg-muted transition-colors"
            aria-label={`Redial ${entry.to}`}
          >
            {answered ? (
              <PhoneOutgoing className="h-4 w-4 shrink-0 text-green-600" />
            ) : (
              <PhoneMissed className="h-4 w-4 shrink-0 text-red-500" />
            )}
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-foreground">{entry.to}</p>
              <p className="truncate text-xs text-muted-foreground">
                {formatTimestamp(entry.session_timestamp)}
              </p>
            </div>
            <div className="shrink-0 text-right">
              <p className="text-xs font-medium text-muted-foreground">
                {entry.duration_formatted}
              </p>
              <p className="text-[10px] uppercase text-muted-foreground/70">
                {answered ? 'Answered' : entry.disposition}
              </p>
            </div>
          </button>
        );
      })}
    </div>
  );
}

export default CallsLogView;
```

- [ ] **Step 2: Type-check**

Run: `cd frontend && npm run type-check`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/WebPhone/CallsLogView.tsx
git commit -m "feat: add CallsLogView list with redial rows"
```

---

## Task 6: Wire tabs + redial into WebPhone.tsx

**Files:**
- Modify: `frontend/src/components/WebPhone/WebPhone.tsx`

The dialer JSX stays inline in `WebPhone.tsx` (it depends on the local `numberSizeClass` helper and many local handlers; extracting it would require drilling ~10 props for no functional gain). Approach A is honored by adding the two NEW view components (`CallsLogView`, `WebPhoneTabs`) and gating the existing dialer sub-tree behind `activeTab`.

- [ ] **Step 1: Add imports**

At the top of `frontend/src/components/WebPhone/WebPhone.tsx`, after the existing `subscribeCoach` import (line 23), add:

```ts
import { WebPhoneTabs, type WebPhoneTab } from './WebPhoneTabs';
import { CallsLogView } from './CallsLogView';
```

- [ ] **Step 2: Add activeTab state and pendingRedialRef**

After the `coachLabel` state declaration (`const [coachLabel, setCoachLabel] = useState<string | null>(null);`, line 118), add:

```ts
  const [activeTab, setActiveTab] = useState<WebPhoneTab>('dialer');
  const pendingRedialRef = useRef<string | null>(null);
```

- [ ] **Step 3: Force dialer tab during active calls**

Immediately after the `inActiveCall` derivation (line 122, `const inActiveCall = callState !== 'idle' || incomingSession !== null;`), add an effect:

```ts
  // During an active/ringing call, force the dialer view and lock tab switching.
  useEffect(() => {
    if (inActiveCall && activeTab !== 'dialer') {
      setActiveTab('dialer');
    }
  }, [inActiveCall, activeTab]);
```

- [ ] **Step 4: Add the redial handler and auto-dial effect**

After the existing coach auto-dial effect (lines 399-404, the one guarded by `pendingCoachRef.current`), add:

```ts
  // Redial from the calls log: switch to the dialer, then place the call on the
  // next render once the number state has settled (mirrors the coach pattern —
  // do NOT call handleCall() synchronously after setNumber).
  const handleRedial = useCallback((redialNumber: string) => {
    pendingRedialRef.current = redialNumber;
    setCoachLabel(null);
    isCoachCallRef.current = false;
    setNumber(redialNumber);
    setActiveTab('dialer');
  }, []);

  useEffect(() => {
    if (state === 'ready' && callState === 'idle' && pendingRedialRef.current) {
      pendingRedialRef.current = null;
      handleCall();
    }
  }, [state, callState, handleCall]);
```

- [ ] **Step 5: Gate the dialer sub-tree behind the active tab and render CallsLogView**

In the `state === 'ready'` block, the idle branch currently starts at the `) : (` on line 707 and its content is the `<div className="flex flex-col items-center gap-6">` dialer. Replace the **idle branch body** so it swaps between the dialer and the calls log based on `activeTab`.

Find this (the else-branch opening on line 707 and the dialer container on line 708):

```tsx
                  ) : (
                    <div className="flex flex-col items-center gap-6">
                      {/* Number display */}
```

Replace with:

```tsx
                  ) : activeTab === 'log' ? (
                    <CallsLogView
                      enabled={state === 'ready' && activeTab === 'log'}
                      onRedial={handleRedial}
                    />
                  ) : (
                    <div className="flex flex-col items-center gap-6">
                      {/* Number display */}
```

This adds no new closing tags — it inserts one `activeTab === 'log' ?` branch before the existing dialer `(`.

- [ ] **Step 6: Render the tab bar as a fixed footer**

The scrollable body `<div>` closes on line 784 (`</div>`) right before the card `</div>` on line 785. Insert the tab bar between them, and only when `state === 'ready'`. Find:

```tsx
              <audio ref={audioRef} autoPlay playsInline className="hidden" />
            </div>
        </div>
      )}
    </>
```

Replace with:

```tsx
              <audio ref={audioRef} autoPlay playsInline className="hidden" />
            </div>

            {state === 'ready' && !incomingSession && callState !== 'connected' && (
              <WebPhoneTabs
                activeTab={activeTab}
                onChange={setActiveTab}
                disabled={inActiveCall}
              />
            )}
        </div>
      )}
    </>
```

The tab bar shows only in the ready state and hides during incoming/connected calls (those views own the full card), consistent with the active-call lock.

- [ ] **Step 7: Type-check**

Run: `cd frontend && npm run type-check`
Expected: PASS.

- [ ] **Step 8: Build to confirm the bundle compiles**

Run: `cd frontend && npm run build`
Expected: `✓ built` with no TypeScript errors.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/components/WebPhone/WebPhone.tsx
git commit -m "feat: add Dialer/Recents tabs and redial to Web Phone"
```

---

## Task 7: Documentation

**Files:**
- Modify: `docs/opbx-userguide/modules/web-phone.mdx`

- [ ] **Step 1: Add a Calls Log section**

In `docs/opbx-userguide/modules/web-phone.mdx`, under the "User Interface" section (after the line describing the app-wide floating dialer, around line 60), add:

```mdx
### Dialer and Recents tabs

The Web Phone has two tabs at the bottom of the panel, like a mobile phone:

- **Dialer** — the keypad for placing calls.
- **Recents** — the last 50 outbound calls placed from your extension. Tap any
  entry to redial it; the phone switches to the Dialer and places the call.

Coaching calls (spy, whisper, barge) are never shown in Recents. Tabs are
locked while a call is in progress.
```

- [ ] **Step 2: Commit**

```bash
git add docs/opbx-userguide/modules/web-phone.mdx
git commit -m "docs: document Web Phone Recents tab"
```

---

## Task 8: Full verification

- [ ] **Step 1: Run the backend test suite for the new controller**

Run: `./run-tests.sh --filter=WebPhoneCallsLogControllerTest`
Expected: PASS (7 tests).

- [ ] **Step 2: Run the existing Web Phone config tests (no regression)**

Run: `./run-tests.sh --filter=WebPhoneConfigControllerTest`
Expected: PASS (11 tests).

- [ ] **Step 3: Frontend type-check and build**

Run: `cd frontend && npm run type-check && npm run build`
Expected: both succeed.

- [ ] **Step 4: PHP lint the new/changed backend files**

Run: `docker compose exec -T app vendor/bin/pint app/Http/Controllers/Api/WebPhoneCallsLogController.php routes/api.php`
Expected: PASS.

Note: `npm run lint` is currently broken repo-wide (missing ESLint config file) and is unrelated to this feature — skip it; rely on `type-check`.

---

## Self-Review Notes

- **Spec coverage:** endpoint (Tasks 1-2), exact-match `from` (Task 2), coaching exclusion (Task 2), 50-cap + desc order (Task 2), org isolation (Task 2), 404 (Task 2), empty state backend (Task 2) + frontend (Task 5); tabs footer (Tasks 4, 6); DialerView/CallsLogView split — implemented as CallsLogView + gated inline dialer (documented deviation in Task 6, functionally equivalent under Approach A); redial via pending-ref pattern (Task 6); tab lock during calls (Task 6); row content number+time+duration+status (Task 5); docs (Task 7). All covered.
- **Types:** `WebPhoneCallLogEntry` (Task 3) is consumed identically in `CallsLogView` (Task 5); `WebPhoneTab` exported from `WebPhoneTabs` (Task 4) and imported in `WebPhone.tsx` (Task 6). Service returns `{ data: WebPhoneCallLogEntry[] }`; controller emits exactly `to, session_timestamp, duration, duration_formatted, disposition`.
- **Deviation:** the spec's `DialerView` extraction is replaced by keeping the dialer inline and gating it behind `activeTab`, because the dialer JSX depends on the local `numberSizeClass` helper and ~10 handlers; extracting adds prop-drilling with no benefit. The two NEW components (`CallsLogView`, `WebPhoneTabs`) are still created, satisfying Approach A's intent (isolated, testable log + tabs).
