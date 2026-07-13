# Web Phone for All Roles Design Specification

> **Feature:** Make the Web Phone available to every user role. Availability is
> determined solely by whether the signed-in user has an assigned USER extension.

## Summary

Today the Web Phone is restricted to the `owner` and `supervisor` roles by two
independent gates: a frontend render guard and a backend 403 role check. This
change removes both role gates. Any authenticated user may open the Web Phone.
If the user has an assigned USER extension (and the organization has Cloudonix
settings), the Web Phone works. If not, it shows the existing "unavailable"
state explaining that no extension is assigned.

Coaching (Spy/Whisper/Barge) remains owner/supervisor-only — it is already
gated at its trigger point on the Live Calls page and is out of scope here.

## Current Behavior

Two role gates exist:

1. **Frontend** — `frontend/src/components/Layout/AppLayout.tsx:37,61`
   ```ts
   const canUseWebPhone = ['owner', 'supervisor'].includes(user?.role ?? '');
   ...
   {canUseWebPhone && <WebPhone />}
   ```
   Non owner/supervisor users never render the `<WebPhone />` component at all.

2. **Backend** — `app/Http/Controllers/Api/WebPhoneConfigController.php:25-29`
   ```php
   if (! in_array($user->role, [UserRole::OWNER, UserRole::SUPERVISOR], true)) {
       return response()->json([
           'message' => 'Web Phone is not available for this role.',
       ], 403);
   }
   ```

The extension check already returns `404` with message
`"No extension is assigned to this user."` when the user has no USER extension.

The frontend `WebPhone` component already handles the 404 case: it sets the
`no_extension` lifecycle state and renders:

> **Web Phone Unavailable**
> No extension is assigned to your account. Web Phone cannot be used.

(`frontend/src/components/WebPhone/WebPhone.tsx:255-256, 564-576`)

## Target Behavior

- Every authenticated user renders the app-wide floating Web Phone.
- On open, the Web Phone requests `GET /v1/webphone/config`:
  - **200** — user has a USER extension and org has Cloudonix settings → phone registers and works.
  - **404 (no extension)** → existing `no_extension` state: "No extension is assigned to your account. Web Phone cannot be used."
  - **404 (no Cloudonix settings)** → existing generic error state.
- Coaching auto-dial from Live Calls is unchanged and remains owner/supervisor-only
  (gated by `canUseLiveCallActions` in `LiveCalls.tsx:125`).

## Changes

### 1. Frontend — remove render gate

**File:** `frontend/src/components/Layout/AppLayout.tsx`

- Remove the `canUseWebPhone` constant (line 37).
- Always render `<WebPhone />` (line 61) instead of conditionally.
- Remove the now-unused `useAuth` import if it is not used elsewhere in the file.
  (It is currently used only for `canUseWebPhone`; confirm and remove if so.)

The `<WebPhone />` component is self-sufficient — it manages loading, no-extension,
and error states internally.

### 2. Backend — remove role gate

**File:** `app/Http/Controllers/Api/WebPhoneConfigController.php`

- Remove the role check block (lines 25-29).
- Remove the now-unused `use App\Enums\UserRole;` import (line 8).
- Keep the extension check (404) and the Cloudonix settings check (404) exactly as-is.

### 3. Tests — invert the role-gate assertions

**File:** `tests/Feature/Api/WebPhoneConfigControllerTest.php`

The three tests asserting a 403 for non-owner/supervisor roles now assert the
opposite — those roles get a `200` config when they have an extension:

- `test_pbx_admin_cannot_get_config` → `test_pbx_admin_with_extension_can_get_config` (expect 200, assert `data.sip_username`).
- `test_pbx_user_cannot_get_config` → `test_pbx_user_with_extension_can_get_config` (expect 200).
- `test_reporter_cannot_get_config` → `test_reporter_with_extension_can_get_config` (expect 200).

Add one test confirming the no-extension path applies to a non-owner role:

- `test_pbx_user_without_extension_gets_404` → expect 404, message `"No extension is assigned to this user."`.

Keep all existing 200/404 tests (owner/supervisor with extension, owner without
extension, cross-org, no Cloudonix settings) unchanged.

### 4. Documentation

Update `docs/opbx-userguide/modules/web-phone.mdx` and any role tables that
state Web Phone is owner/supervisor-only, to reflect that all roles can use the
Web Phone provided they have an assigned extension. (Coaching remains
owner/supervisor-only and is documented separately.)

## Out of Scope

- Coaching (Spy/Whisper/Barge) permission changes — unchanged, remains owner/supervisor-only.
- Any change to extension provisioning or Cloudonix settings.
- Any change to the Web Phone UI beyond what already exists.

## Verification

```bash
./run-tests.sh --filter=WebPhoneConfigControllerTest
cd frontend && npm run lint && npm run type-check
```

- Backend: non-owner/supervisor role **with** extension → 200; **without** → 404.
- Frontend: type-check passes with the gate removed and any unused import cleaned up.

## References

- Frontend gate: `frontend/src/components/Layout/AppLayout.tsx:37,61`
- Backend gate: `app/Http/Controllers/Api/WebPhoneConfigController.php:25-29`
- No-extension UI: `frontend/src/components/WebPhone/WebPhone.tsx:255-256, 564-576`
- Coaching gate (unchanged): `frontend/src/pages/LiveCalls.tsx:125,263`
- Tests: `tests/Feature/Api/WebPhoneConfigControllerTest.php`
