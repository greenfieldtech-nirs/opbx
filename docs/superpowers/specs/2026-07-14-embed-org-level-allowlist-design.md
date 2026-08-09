# Embedded Dialer: Organization-Level Allowed Domains

**Date:** 2026-07-14
**Status:** Implemented

## Problem

The Embedded Dialer's "allowed domains" allowlist was stored per embed token
(`user_embed_tokens.allowed_domains`), i.e. per user/extension. This is the
wrong granularity: the set of websites a company embeds its PBX into is a
property of the **organization**, not of each individual user. Managing the same
domain list separately on every user is redundant and error-prone.

## Decision

Move the allowlist to the **organization level**, as a single source of truth.

- **Storage:** new `embed_allowed_domains` JSON column on `cloudonix_settings`
  (the existing per-org Cloudonix configuration table). Chosen over a new
  dedicated table (option B) — no other org-level embed config is planned, so a
  column on the existing table is the minimal fit.
- **Per-user column removed entirely:** `allowed_domains` dropped from
  `user_embed_tokens`. No per-user override (option B rejected) — single source
  of truth. The embed token still exists per user; only the allowlist moved.
- **Breaking data change accepted:** the feature is unreleased (develop only,
  not merged), so existing per-user values are dropped with no data migration.

## Architecture

Three consumers of the allowlist, all re-pointed at the org list:

1. `ResolveEmbedToken` middleware — Origin check. Resolves
   `token → organization → cloudonixSettings.embed_allowed_domains`
   (via `OrganizationScope::bypass`). The `originAllowed()` helper signature is
   unchanged (still `(origin, domains)`), so its unit tests are unaffected.
2. `EmbedDialerController::frameAncestors` — `frame-ancestors` CSP. Same
   resolution source.
3. Configuration surface — moved from the per-user embed-token endpoint to the
   organization **Settings → Cloudonix** endpoint (`PUT /v1/settings/cloudonix`,
   owner-only) and Settings page UI.

`UserEmbedToken` gains an `organization()` relation (org-scope-bypassed) so the
consumers can reach the settings.

## Validation

Hostname regex (unchanged from the old per-user rule) moves to
`UpdateCloudonixSettingsRequest`: `embed_allowed_domains` is an optional array,
each entry a valid hostname (no scheme).

## API changes

- `PATCH /v1/users/{user}/embed-token` — no longer accepts/returns
  `allowed_domains`; only icon position/color.
- `GET/PUT /v1/settings/cloudonix` — now includes `embed_allowed_domains`.

## UI changes

- `EmbeddedDialerDialog` (per-user) — domain editor removed, replaced with a
  note pointing to Settings. Keeps icon config + one-time snippet reveal.
- Settings page (Cloudonix) — new "Embedded Dialer — Allowed Domains" editor,
  saved as part of the existing Cloudonix settings save flow.

## Testing

- Migration test: `user_embed_tokens` has no `allowed_domains`;
  `cloudonix_settings` has `embed_allowed_domains`.
- Config endpoint / dialer page tests: allowlist seeded on `CloudonixSettings`,
  Origin + frame-ancestors gated by the org list.
- New settings test: owner can set the list, GET returns it, invalid hostnames
  are rejected (422).
- Full backend suite green (1410 passed); frontend type-check + build clean;
  live curl verified (200 listed / 403 unlisted / 200 no-origin; CSP reflects
  org list).
