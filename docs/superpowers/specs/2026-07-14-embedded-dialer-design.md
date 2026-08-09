# Embedded Dialer — Design Spec

**Date:** 2026-07-14
**Status:** Approved (design)
**Branch:** develop

## 1. Objective

Enable a remotely-loaded, Google-Analytics-style JavaScript snippet that embeds a
specific user's Web Phone widget into any third-party website — primarily CRM web
applications. A developer pastes a one-line snippet into their page and the user's
Web Phone renders, can place/receive calls, and can be driven programmatically via
a small Widget API (e.g. `OpbxDialer.dial("+12127773456")`).

## 2. Requirements (from stakeholder)

MUST have:
- Remotely loaded via a simple JS snippet (like Google Analytics).
- Security via API-key/token validation.
- Developer-configurable: widget icon **position** and icon **background color**, via a config variable.
- A **Widget API** allowing host-page JS to command the widget (dial, hangup, open, close, subscribe to events).

## 3. Key Decisions (resolved during brainstorming)

1. **Identity/auth model: per-user embed token (Model A).** A token bound to one
   specific user/extension. Configured into the snippet. Widget calls a public embed
   endpoint that returns SIP config only for that bound extension.
2. **SIP credential handling.** Cloudonix does **not** support short-lived/ephemeral
   WebRTC credentials, so the endpoint returns the extension's SIP password (exactly as
   `/webphone/config` does today). The **embed token is the security boundary**, not the
   SIP password. A client-side salt/encryption scheme was explicitly rejected: the browser
   must hold the usable plaintext password to register with JsSIP, so any client-side
   wrapping is obfuscation, not security (the client holds both ciphertext and key).
   Residual risk (permanent SIP password reaches a third-party page) is documented;
   **Regenerate** is the fast mitigation (stops new fetches); rotating the extension
   password in Cloudonix is the full mitigation if a password is known-leaked.
3. **Token domain enforcement (Q3 Option B).** Server validates the request `Origin`
   against the token's allowlisted domains for the access decision **and** returns a
   per-request `Access-Control-Allow-Origin` reflecting only the allowlisted origin, so
   browsers also enforce it. Honest ceiling: cannot stop a non-browser client (curl) that
   already has the token; revocation handles that. Blocks all real-browser cross-site abuse.
4. **Delivery: script loader + iframe body (Q4 Option C).** The pasted snippet is a tiny
   GA-style loader; the dialer itself renders in an **iframe** served from OpBX. This
   sidesteps Tailwind style-collisions, keeps the mic permission and SIP password inside
   *our* frame, makes the config fetch same-origin from the iframe, and turns the domain
   allowlist into a clean `frame-ancestors` CSP.
5. **Widget API: auto-dial.** `dial()` places the call immediately (no confirm step in v1).
6. **Token management.** Owner + PBX Admin only. Managed from the **Users page** at the
   table/row level. A token is **auto-generated on user creation**; admins **Regenerate**
   from a per-row action. Token is **never visible** except **shown once** (as part of the
   copy-paste snippet) at generation/regeneration time (Q7 Option A). Token **lives until
   revoked/regenerated** (no expiry). **Existing users are backfilled** in the migration
   (Q8 Option A); their usable snippet is revealed on first Regenerate.

## 4. Architecture

Three moving parts:

1. **Loader snippet** — a tiny IIFE the developer pastes. Reads a config object
   (`token`, `iconPosition`, `iconBackgroundColor`), injects a launcher + `<iframe>`
   pointing at OpBX's embed route, and exposes `window.OpbxDialer`.
2. **Embed widget bundle** — a separate Vite `build.lib` entry, served by OpBX, runs
   inside the iframe. Mounts `<WebPhone />` wrapped in a minimal `QueryClientProvider` +
   a token-configured axios instance (no Auth/Config/Router context — the widget does not
   use them). Talks to the embed API, registers via JsSIP, listens for `postMessage`.
3. **Backend embed API** — public throttled config/calls-log endpoints, an iframe-serving
   route with per-token `frame-ancestors` CSP, and token management on the Users API.

**Request flow:**
```
CRM page (has snippet)
  → loader creates iframe: https://opbx…/embed/dialer?token=…
      → OpBX serves iframe HTML with CSP: frame-ancestors <allowlisted domains>
      → widget bundle loads, calls GET /v1/embed/config (Bearer opbxd_… )
          → backend validates token + Origin, returns SIP config (+ per-request CORS)
      → JsSIP registers with Cloudonix (wss)
  ← widget ready; posts {type:'ready'} to parent
CRM JS → OpbxDialer.dial("+1…") → postMessage → iframe → auto-place call
iframe → postMessage call.started/ended/failed → OpbxDialer.on(...) callbacks
```

## 5. Data Model

**New table `user_embed_tokens`** (one-to-one with users):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK → users, unique, cascade delete | one token per user |
| `organization_id` | FK → organizations | tenant scoping (mirrors ApiKey) |
| `token` | string(64) unique | SHA-256 hex hash, never plaintext |
| `allowed_domains` | json | array of allowlisted domains |
| `icon_position` | string | enum-backed: `bottom-right` \| `bottom-left` \| `top-right` \| `top-left` |
| `icon_background_color` | string | hex, validated |
| `last_used_at` | timestamp null | throttled write (≤1/5s) |
| timestamps | | |

There is **no `revoked_at` column**. Because it is one-token-per-user and Regenerate
rotates the `token` hash **in place**, the old hash is destroyed the instant the new
one is written — a captured old token stops resolving immediately (it no longer matches
any stored hash). "Revoke" is therefore implemented as "regenerate and discard the
snippet." (If a future version needs a disable-without-reissue state, add `revoked_at`
then — YAGNI for v1.)

- Token prefix `opbxd_` + `Str::random(40)`, stored hashed (`hash('sha256', …)`), plaintext returned once.
- **Regenerate** rotates the `token` hash in place, **keeps** `allowed_domains`/icon config, updates `updated_at`; old token stops working immediately.
- **Backfill migration**: create a token row for every existing user (hashed generated secret; plaintext not surfaced — reveal happens on Regenerate).
- Model `UserEmbedToken`: `belongsTo(User)`, `OrganizationScope`. `User::embedToken()` hasOne.
- Enum `EmbedIconPosition` (PascalCase class, UPPER_SNAKE cases, string-backed).

## 6. Backend API

**Public embed-config** — `GET /v1/embed/config`
- Outside the `auth:sanctum` group (public, like DNI swap). Middleware: `throttle:embed`, new `resolve.embed.token`.
- `resolve.embed.token`: reads `Authorization: Bearer opbxd_…`, resolves by hash, rejects missing/unresolvable (401). Validates request `Origin` against `allowed_domains` → 403 if not allowed. Sets `Access-Control-Allow-Origin: <that origin>` (per-request CORS). Throttled `last_used_at` bump. Sets the request user resolver to the bound `User` (so downstream reuse of config logic works) via `OrganizationScope::bypass` as needed.
- Controller resolves the bound user's `type=USER` extension and returns the **same shape** as `/webphone/config`. 404 if no extension.
- **Refactor:** extract the SIP-config building logic out of `WebPhoneConfigController` into a shared service (e.g. `WebPhoneConfigBuilder`) so both endpoints stay in sync.

**Embed calls-log** — `GET /v1/embed/calls-log` (same auth) so the Recents tab works in the embed. Reuses the calls-log query logic (also extracted/shared).

**Iframe HTML route** — `GET /embed/dialer` (web route, not `/api`)
- Serves minimal HTML that loads the embed widget bundle and reads the token from the query param. Sets `Content-Security-Policy: frame-ancestors <allowed_domains for token>` per-request (replaces blanket `X-Frame-Options: SAMEORIGIN` for this route only). If token invalid/missing → render a minimal error, no framing granted.
- **Loader asset** — `GET /embed/loader.js` serves the loader IIFE (static/cacheable).

**Token management on Users API** (Owner + PBX Admin only; policy-enforced; supervisors/others denied):
- `POST /v1/users/{user}/embed-token/regenerate` → rotates token, returns plaintext **once** + full snippet.
- `PATCH /v1/users/{user}/embed-token` → update `allowed_domains`, `icon_position`, `icon_background_color`. Returns non-secret fields only.
- `GET /v1/users/{user}/embed-token` → returns non-secret config (domains, icon, last_used_at) — never the token.
- User creation auto-generates a token; the create response surfaces the one-time snippet.
- `EmbedTokenResource` never includes token hash/plaintext except the one-time regenerate/create response.

**CORS:** the `resolve.embed.token` middleware sets a dynamic per-request `Access-Control-Allow-Origin` for `/v1/embed/*`. `config/cors.php` allowlist unchanged for everything else. (Embed endpoints are token-authenticated and non-credentialed, so `*`-vs-credentials incompatibility does not apply — but we reflect the exact origin anyway.)

## 7. Widget Bundle & Widget API

**Bundle:**
- New Vite entry (`build.lib` / second `rollupOptions.input`) → standalone `embed-widget.[hash].js` + CSS, separate from the SPA.
- Wraps `<WebPhone />` in `QueryClientProvider` + token-configured axios (token from iframe URL param, sent as `Bearer opbxd_…` on `/v1/embed/*`). No Auth/Config/Router providers.
- **`WebPhone` change:** parameterize the config source. Today it calls `getWebPhoneConfig()` (`/webphone/config`) and `getWebPhoneCallsLog()` hardcoded. Add a prop/mode so the SPA uses `/webphone/*` and the embed uses `/embed/*`. One `WebPhone` component, two config sources.

**`window.OpbxDialer` (host-side, created by the loader):**
```js
OpbxDialer.dial("+12127773456")  // auto-places immediately
OpbxDialer.hangup()
OpbxDialer.open()  /  OpbxDialer.close()
OpbxDialer.on(event, cb)  // events: 'ready' | 'call.started' | 'call.ended' | 'call.failed'
```
- Each method posts `{source:'opbx-dialer', type:'command', name, args}` to the iframe with an **explicit target origin** (the OpBX origin), never `"*"`.
- The iframe validates every inbound message: `event.origin` must equal the host page origin **and** be in the token's `allowed_domains`; `event.source` must be the parent window. Reject otherwise.
- Widget → host events posted back with explicit target origin (the host page origin); the loader dispatches to `on()` subscribers.
- **Inside the widget:** reuse the `webPhoneBus`/`pendingCoachRef` auto-dial pattern — a postMessage adapter queues the dial so `dial()` reuses the proven "set number → effect fires call" flow (not synchronous `setNumber+handleCall`).
- **Residual security (documented):** any script on an allowlisted host page can call `OpbxDialer.dial()`. Inherent to client embeds; the host page is trusted once allowlisted.

## 8. Users Page UI

- New per-row action (Owner + PBX Admin only; already hidden for supervisors who are view-only): an **Embed** icon opening an **Embedded Dialer** dialog for that user.
- Dialog contents:
  - **Allowed domains** editor (list).
  - **Icon position** select (4 corners) + **background color** picker.
  - **Regenerate** button → confirm → rotates token → shows the **one-time snippet** (copy button), mirroring the API Keys "shown once" UX.
- **On user creation:** success dialog surfaces the one-time snippet.
- Config changes (domains/icon) save via `PATCH`; token never displayed except at regenerate/create.

**Snippet the developer pastes:**
```html
<script>
  (function(o,p,b,x){/* loader: injects iframe + window.OpbxDialer */})
  (window,document,'https://opbx…/embed/loader.js', {
    token: 'opbxd_…',
    iconPosition: 'bottom-right',
    iconBackgroundColor: '#007acc'
  });
</script>
```

## 9. Testing & Docs

**Backend (MySQL, `./run-tests.sh`):**
- Token resolve/hash; missing/unresolvable token rejected (401).
- Origin allowlist accept/reject; per-request CORS header set to the exact origin.
- Config returns bound extension's SIP config; 404 when no extension.
- Regenerate rotates token; old token dies immediately; domains/icon preserved.
- Backfill migration creates a row per existing user.
- Policy: Owner + PBX Admin allowed; supervisor and others denied.
- Org isolation (token cannot resolve cross-org data).
- Iframe route sets `frame-ancestors` from the token's domains.

**Frontend:** type-check + build pass; embed bundle builds as a separate artifact.

**Docs:** new `docs/opbx-userguide` Embedded Dialer page (snippet usage, Widget API reference, security/residual-risk, domain allowlist, regenerate). Cross-link from the Web Phone page.

## 10. Scope Boundaries (v1 — YAGNI)

- No per-token analytics/usage dashboard beyond `last_used_at`.
- No customization beyond icon position + background color.
- Widget API is exactly the 4 commands + 4 events above.
- No server-side token broker / no ephemeral SIP credentials (Cloudonix limitation).
- No changes to the existing `opbxk_` API-key system (embed tokens are a separate `opbxd_` type).
