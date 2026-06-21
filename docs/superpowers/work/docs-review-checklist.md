# Documentation Refresh Review Checklist

**Date:** 2026-06-22
**Spec:** `docs/superpowers/specs/2026-06-21-comprehensive-documentation-refresh.md`
**Plan:** `docs/superpowers/plans/2026-06-21-comprehensive-documentation-refresh.md`

## OpenAPI

- [x] Spec generated from current source routes (`docs/superpowers/work/route-list.json`)
- [x] `docs/opbx-openapi/openapi.yaml` root spec updated with current tags, security schemes, and source note
- [x] All missing routes added (auto-dialer, broadcasting, webhooks, platform)
- [x] Stale routes removed (call-logs)
- [x] PATCH operations added for all `apiResource` update endpoints
- [x] Redocly lint: **0 errors**, 102 warnings (pre-existing/stylistic)
- [x] Route coverage: all non-HEAD/framework routes documented

## Docusaurus User Guide

- [x] Core pages refreshed (`index.mdx`, installation, first-login, cloudonix-setup, concepts, call-routing-overview)
- [x] Module index updated
- [x] Existing module pages refreshed (users, extensions, phone-numbers, ring-groups, business-hours, ivr-menus, conference-rooms, inbound-blacklist, outbound-whitelist, recordings, reporting)
- [x] Missing module pages created (live-calls, call-logs, call-notifications, settings, auto-dialer-campaigns, distribution-lists, ai-assistants, ai-load-balancers)
- [x] Worker-service guides created (dialer-worker, amd-worker)
- [x] Data model reference updated and missing models added
- [x] Architecture guides created (index, multi-tenancy, webhooks, call-flow, security, real-time)
- [x] Administration index refreshed to point to current Settings/Cloudonix docs

## Standalone Docs

- [x] `docs/WEBHOOK-AUTHENTICATION.md` refreshed with current middleware and routes
- [x] `docs/DATABASE-PERSISTENCE.md` refreshed with current storage layout

## Validation Notes

- Route coverage comparison uses `docs/superpowers/work/route-list.json` plus the bundled OpenAPI spec. The 15 routes shown as "extra" in OpenAPI are real routes from `routes/platform.php` and the Laravel broadcasting auth endpoint; they are intentionally documented even though `route:list` did not assign generated names to the platform group.
- Docusaurus site build was not validated because no Docusaurus config (`docusaurus.config.*`, `package.json`, `sidebars.js`) is present in `docs/opbx-userguide/` or `docs/`. Frontmatter and relative-link checks were performed by the technical-writer subagent instead.

## Known Follow-Ups

- Add a `docusaurus.config.js` and `package.json` to `docs/opbx-userguide/` if a buildable docs site is required.
- Consider moving or removing duplicate Cloudonix setup content between `administration/index.mdx` and `installation/cloudonix-setup.mdx`.
