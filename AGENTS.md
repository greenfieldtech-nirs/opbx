# AGENTS.md - Development Guidelines for OpBX

This document provides essential information for AI coding agents working in this repository.

## Project Overview

OpBX is an open-source business PBX platform built on Laravel (PHP 8.4) and React (TypeScript).
- **Backend**: Laravel 12 API with MySQL + Redis
- **Frontend**: React 18 SPA with Vite, TanStack Query, shadcn/ui
- **Architecture**: Multi-tenant, uses Cloudonix CPaaS for VoIP

## Build / Test / Lint Commands

### PHP (Laravel)
```bash
# Run all tests
./run-tests.sh

# Run single test (class or method)
php artisan test --filter=TestClassName
php artisan test --filter=test_method_name

# Lint/fix PHP code (Laravel Pint)
vendor/bin/pint              # Check all
vendor/bin/pint --dirty      # Fix only changed files

# Development server
composer dev                 # Starts server + queue + logs + Vite concurrently
php artisan serve            # API only
php artisan queue:listen     # Queue worker
```

### Frontend (React)
```bash
cd frontend

npm run dev                  # Vite dev server on :5173
npm run build                # Production build (runs tsc + vite build)
npm run lint                 # ESLint on .ts/.tsx files
npm run type-check           # TypeScript check only (tsc --noEmit)
```

### Docker
```bash
docker compose up -d                        # Full stack startup
docker compose logs -f [service]            # View logs
docker compose exec app php artisan migrate # Run migrations
```

## Code Style Guidelines

### PHP (PSR-12 + Laravel Conventions)
- Declare `strict_types=1` at the top of every file
- 4-space indentation, LF line endings, UTF-8
- PSR-4 autoloading: `App\` → `app/`
- Classes: `PascalCase`, Methods: `camelCase`, Constants: `UPPER_SNAKE_CASE`
- Database tables: `snake_case`, plural (e.g., `ring_groups`)
- Enums: `PascalCase` with cases in `UPPER_SNAKE_CASE`
- Group imports: built-in PHP, then Laravel, then App

### TypeScript / React
- 2-space indentation for TSX/TS files
- Use `@/` path alias for imports from `src/`
- Components: `PascalCase` (e.g., `BusinessHoursForm.tsx`)
- Hooks: `camelCase` starting with `use` (e.g., `useAuth()`)
- Types/Interfaces: `PascalCase` (e.g., `BusinessHoursConfig`)
- Prefer functional components with hooks

### Database / Migrations
- Table/column names: `snake_case`, tables are plural
- Foreign keys: `{table}_id` (e.g., `organization_id`)
- Timestamps: always include `created_at` and `updated_at`
- Use enums for fixed value sets

## Testing Conventions

- Tests in `tests/Unit/` and `tests/Feature/`
- Extend `Tests\TestCase`
- Test method names: descriptive `snake_case` (e.g., `test_user_can_create_extension()`)
- Use `Database\Factories\` for model factories
- Use `RefreshDatabase` trait for feature tests
- SQLite in-memory for fast tests (configured in phpunit.xml)

## Architecture Patterns

### Multi-Tenancy
- All models scoped by `organization_id`
- Use `OrganizationScope` trait for automatic scoping
- Controllers get current user via `$this->getAuthenticatedUser()`

### Webhook Handling
- Idempotency keys in Redis: `idem:webhook:{event_id}`
- Locks: `lock:call:{call_id}`
- Always verify webhook signatures (see `VerifyCloudonixSignature` middleware)

### Error Handling
- PHP: Use custom exceptions in `app/Exceptions/`
- Return structured JSON errors with consistent format
- Log with context (especially `call_id` for webhook logs)

### Frontend State Management
- Use TanStack Query for server state
- Use React Context for global client state (Auth, Config)
- Form validation with Zod + react-hook-form

## Linting & Quality Checks

**Before committing, run:**
```bash
# PHP
vendor/bin/pint --dirty

# Frontend
cd frontend && npm run lint && npm run type-check

# Tests
./run-tests.sh
```

## Environment Variables

Key variables (see `.env.example`):
- `CLOUDONIX_API_TOKEN` - Bearer token for Cloudonix API
- `CLOUDONIX_WEBHOOK_SECRET` - For webhook signature verification
- `NGROK_AUTHTOKEN` - For local webhook testing

Never commit `.env` files or secrets to git.

## Documentation References

- **Cloudonix Documentation**: https://developers.cloudonix.com/
- **Cloudonix REST API Playground**: https://developers.cloudonix.com/cloudonixRestOpenAPI
- **OPBX Documentation**: https://developers.cloudonix.com/opbx
- **OPBX REST API Playground**: https://developers.cloudonix.com/opbxRestOpenAPI
- **Laravel Docs**: https://laravel.com/docs/12.x
- **shadcn/ui Components**: https://ui.shadcn.com/

---

**Last Updated**: 2026-02-17
**Agent Instructions Version**: 1.0
