# AGENTS.md - Development Guidelines for OpBX

This document provides essential information for AI coding agents working in this repository.

## Project Overview

OpBX is an open-source business PBX platform built on Laravel (PHP 8.4) and React (TypeScript).
- **Backend**: Laravel 12 API with MySQL + Redis
- **Frontend**: React 18 SPA with Vite, TanStack Query, shadcn/ui
- **Dialer Worker**: Go-based service for outbound campaigns
- **Architecture**: Multi-tenant, uses Cloudonix CPaaS for VoIP

## Project Memory

The `/memory/` directory contains per-module source maps documenting every file, route,
model, service, and component. **Read `memory/_index.md` first** to find which module
file(s) to consult before working on any feature. Update memory files after making changes.

## Build / Test / Lint Commands

### PHP (Laravel)
```bash
./run-tests.sh                             # All tests (runs inside Docker)
./run-tests.sh --filter=TestClassName      # Single test class
./run-tests.sh --filter=test_method_name   # Single test method

vendor/bin/pint                            # Lint/fix all PHP (PSR-12)
vendor/bin/pint --dirty                    # Fix only changed files

composer dev                               # Dev server + queue + logs + Vite
php artisan serve                          # API only
php artisan queue:listen                   # Queue worker
```

### Frontend (React)
```bash
cd frontend
npm run dev                  # Vite dev server on :5173
npm run build                # Production build (tsc + vite build)
npm run lint                 # ESLint on .ts/.tsx files
npm run type-check           # TypeScript check only (tsc --noEmit)
```

### Dialer Worker (Go)
```bash
cd dialer-worker
make build                   # Build binary
make run                     # Run locally
```

### Docker
```bash
docker compose up -d                        # Full stack startup
docker compose logs -f [service]            # View logs
docker compose exec app php artisan migrate # Run migrations
# IMPORTANT: Wait 120 seconds after restart before testing.
```

## Code Style Guidelines

### PHP (PSR-12 + Laravel Conventions)
- `declare(strict_types=1)` at the top of **every** file
- 4-space indentation, LF line endings, UTF-8
- PSR-4 autoloading: `App\` -> `app/`
- Import order: built-in PHP -> Laravel/Illuminate -> App namespace
- Classes: `PascalCase` | Methods: `camelCase` | Constants: `UPPER_SNAKE_CASE`
- Enums: `PascalCase` class, `UPPER_SNAKE_CASE` cases, backed by `string` values
- Use `match` expressions over `switch`; use constructor property promotion
- Controllers: thin logic, delegate to services; most extend `AbstractApiCrudController`
- Validation: use dedicated FormRequest classes in `app/Http/Requests/`
- API responses: use Resource classes in `app/Http/Resources/`
- Error handling: structured JSON errors; log with context (`call_id` for webhooks)

### TypeScript / React
- 2-space indentation for TSX/TS files
- `@/` path alias for imports from `src/` (configured in tsconfig.json)
- TypeScript strict mode is **off** (`strict: false` in tsconfig.json)
- Components: `PascalCase` filenames (e.g., `BusinessHoursForm.tsx`)
- Hooks: `camelCase` starting with `use` (e.g., `useAuth()`)
- Types/Interfaces: `PascalCase` (e.g., `BusinessHoursConfig`)
- Functional components only; use hooks for state and side effects
- Server state: TanStack Query (`useQuery`, `useMutation`)
- Client state: React Context (Auth, Config)
- Forms: Zod schemas + react-hook-form
- UI components: shadcn/ui (Radix primitives + Tailwind CSS)

### Go (Dialer Worker)
- `gofmt` for formatting
- Package names: lowercase, single word (e.g., `executor`, `limiter`)
- Variables: `camelCase` | Constants: `UPPER_SNAKE_CASE`
- Import order: standard library -> third-party -> internal
- HTTP client: `DisableKeepAlives: true` (required for DNS freshness with nginx)

### Database / Migrations
- Table names: `snake_case`, plural (e.g., `ring_groups`, `did_numbers`)
- Column names: `snake_case`
- Foreign keys: `{singular_table}_id` (e.g., `organization_id`)
- Always include `created_at` and `updated_at` timestamps
- Use PHP enums for fixed value sets; back with string columns

## Testing Conventions

- Tests in `tests/Unit/` and `tests/Feature/`; extend `Tests\TestCase`
- Method names: `test_descriptive_snake_case()` with `: void` return type
- SQLite in-memory for all tests (configured in phpunit.xml)
- Use `RefreshDatabase` trait for feature tests
- Use `Database\Factories\` for model factories

## Architecture Patterns

### Multi-Tenancy
- All models scoped by `organization_id` via `#[ScopedBy([OrganizationScope::class])]`
- Bypass pattern: `OrganizationScope::bypass(fn() => ...)` (for webhooks, platform admin)
- Middleware `tenant.scope` validates org exists and is active on all API routes

### Webhook Handling
- Idempotency keys in Redis: `idem:webhook:{event_id}`
- Distributed locks: `lock:call:{call_id}`
- Signature verification: `VerifyCloudonixSignature` middleware (Bearer or HMAC-SHA256)
- Voice webhooks return **CXML** (not JSON) on error

### Voice Routing
- Strategy pattern: 8 strategies in `app/Services/VoiceRouting/Strategies/`
- CXML generation: `app/Services/CxmlBuilder/CxmlBuilder.php` (DOMDocument-based)
- Cache-aside pattern via `VoiceRoutingCacheService` with Redis

### Frontend State Management
- TanStack Query for server state (queries + mutations)
- React Context for global client state (Auth, Config)
- Form validation: Zod schemas + react-hook-form + @hookform/resolvers

## Pre-Commit Checklist

```bash
vendor/bin/pint --dirty                          # PHP lint
cd frontend && npm run lint && npm run type-check # Frontend lint + types
./run-tests.sh                                   # All tests
```

## Environment Variables

Key variables (see `.env.example`):
- `CLOUDONIX_API_TOKEN` - Bearer token for Cloudonix API
- `CLOUDONIX_WEBHOOK_SECRET` - Webhook signature verification
- `NGROK_AUTHTOKEN` - Local webhook testing
- `DIALER_WORKER_API_TOKEN` - Dialer worker authentication

Never commit `.env` files or secrets to git.

## Documentation References

- **Cloudonix Docs**: https://developers.cloudonix.com/
- **Cloudonix REST API**: https://developers.cloudonix.com/cloudonixRestOpenAPI
- **OPBX Docs**: https://developers.cloudonix.com/opbx
- **OPBX REST API**: https://developers.cloudonix.com/opbxRestOpenAPI
- **Laravel Docs**: https://laravel.com/docs/12.x
- **shadcn/ui**: https://ui.shadcn.com/

---

**Last Updated**: 2026-04-06
**Agent Instructions Version**: 2.0
