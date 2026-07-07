# Comprehensive OpBX Documentation Refresh

> **Status:** Approved for implementation
> **Approach:** Option C — Hybrid refresh of existing `docs/opbx-userguide/` and `docs/opbx-openapi/`, plus new worker-service and architecture guides.
> **Author:** John The Great (senior engineering lead)
> **Date:** 2026-06-21

---

## Goal

Bring the entire OpBX documentation set into sync with the codebase as it exists today. The deliverables are:

1. A **generated-from-source OpenAPI 3.1.0 specification** covering every route in `routes/api.php`, `routes/webhooks.php`, and `routes/platform.php`.
2. An updated **Docusaurus user guide** (`docs/opbx-userguide/`) that accurately describes every module and workflow.
3. New **architecture and operational guides** for deployment, webhook auth, multi-tenancy, and worker services.
4. Consistent formatting, current terminology, and removal of stale/incorrect information.

## What Will Be Produced

| Deliverable | Location | Description |
|-------------|----------|-------------|
| OpenAPI root spec | `docs/opbx-openapi/openapi.yaml` | Generated from `php artisan route:list --json` + route docblocks + controller FormRequests |
| OpenAPI path files | `docs/opbx-openapi/paths/**/*.yaml` | One file per route or resource group |
| OpenAPI schemas | `docs/opbx-openapi/components/schemas/*.yaml` | Models and request/response bodies |
| OpenAPI README | `docs/opbx-openapi/README.md` | How to regenerate the spec |
| Docusaurus intro | `docs/opbx-userguide/index.mdx` | Updated welcome + architecture + role matrix |
| Installation guide | `docs/opbx-userguide/installation/index.mdx` | Current Docker setup, .env, ngrok, first login |
| Module guides | `docs/opbx-userguide/modules/*.mdx` | One page per functional module |
| Worker-service guides | `docs/opbx-userguide/workers/dialer-worker.mdx`, `docs/opbx-userguide/workers/amd-worker.mdx` | Overview + build/run instructions |
| Architecture guides | `docs/opbx-userguide/architecture/*.mdx` | Multi-tenancy, webhooks, call flow, security |
| Standalone security doc | `docs/WEBHOOK-AUTHENTICATION.md` | Keep and refresh from existing doc |
| Standalone DB doc | `docs/DATABASE-PERSISTENCE.md` | Keep and refresh if needed |

## Scope Boundaries

- **In scope:** All Laravel REST API routes, webhook routes, platform routes, frontend pages, and worker services.
- **Out of scope:** Rewriting application code, fixing tests, redesigning the docs site theme, or adding new features.
- **Generated OpenAPI only:** The OpenAPI spec must reflect current source code, not aspirational endpoints.

## Source-of-Truth Files

The documentation must be derived from these files:

- `routes/api.php`
- `routes/webhooks.php`
- `routes/platform.php`
- `routes/channels.php`
- `app/Http/Controllers/**/*.php`
- `app/Http/Requests/**/*.php`
- `app/Models/**/*.php`
- `frontend/src/pages/**/*.tsx` (for UI workflow descriptions)
- `docker-compose.yml.example`
- `.env.example`
- `dialer-worker/` (README, Makefile, source)
- `amd-worker/` (README, pom.xml, source)
- `.my_agent/memory/*.md` (for cross-checking module boundaries)

## Key Requirements

1. **OpenAPI generation must be reproducible.** Provide a script or documented command chain that regenerates the spec from `route:list`.
2. **All routes must be documented.** No route in `routes/*.php` may be missing from the OpenAPI spec.
3. **All request/response schemas must match current FormRequests and Resources.** Do not copy stale schemas.
4. **User-guide module pages must match current UI pages.** Add pages for modules that exist in code but not in docs.
5. **Worker guides must be overview + build/run only**, not deep code walkthroughs.
6. **Terminology must be consistent** with the codebase (e.g., `organization_id`, `did_numbers`, `ring_groups`, `auto_dialer_campaigns`).
7. **Security-sensitive details** (auth modes, webhook signatures, idempotency) must be accurate and current.

## Implementation Plan

See `docs/superpowers/plans/2026-06-21-comprehensive-documentation-refresh.md`.

## Success Criteria

- `docs/opbx-openapi/openapi.yaml` can be bundled with `swagger-codegen` or `redocly` without errors.
- Every route name returned by `php artisan route:list --json` appears in the OpenAPI spec.
- Every existing `docs/opbx-userguide/modules/*.mdx` file is either updated or removed with a redirect note.
- New module pages exist for Live Calls, Call Notifications, Platform Organization Detail, and any other missing UI modules.
- `docs/opbx-userguide/workers/*.mdx` documents build/run steps for both workers.
- Docusaurus can build the user guide (`npm run build` inside `docs/opbx-userguide/`) without broken links.
