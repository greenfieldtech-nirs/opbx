# OPBX API OpenAPI Documentation

This directory contains the complete OpenAPI 3.1 specification for the OPBX (Open Source Business PBX) API.

## Keeping it in sync with the code

The spec is maintained by hand from the code. To find routes that are missing or changed,
list the routes (with their middleware) from the running stack:

```bash
docker compose exec -T app php artisan route:list --json > /tmp/route-list.json
```

Compare it with the `paths` block in `openapi.yaml` and the path files under `paths/`, then
update the `$ref` entries and path files so that every route is documented. Field-level
sources: FormRequest `rules()` in `app/Http/Requests/**` (and inline `$request->validate()`),
API Resources in `app/Http/Resources/**`, and the route middleware in `routes/api.php`,
`routes/platform.php` and `routes/webhooks.php` for auth.

## Validation

Lint the specification with Redocly before committing (must report 0 errors):

```bash
docker run --rm -v "$PWD/docs/opbx-openapi":/spec docker.io/redocly/cli:2.58.2 \
  lint /spec/openapi.yaml --format=summary
```

## Structure

```
docs/opbx-openapi/
├── openapi.yaml                # Entry point: info, servers, path $refs, security schemes, tags
├── README.md                   # This file
├── generate_endpoints.sh       # Obsolete one-off generator (do not run, it overwrites path files)
├── components/
│   ├── schemas/                # Data models (User.yaml, Extension.yaml, Error.yaml, ...)
│   │   ├── cxml/              # CXML response schema
│   │   └── webhooks/          # Cloudonix webhook payloads
│   ├── parameters/
│   │   ├── path/              # Path parameters (user, extension, ...)
│   │   └── query/             # Query parameters (page, per_page, sort_order, ...)
│   ├── responses/             # Common responses (401, 403, 404, 409, 422, 429, 500)
│   └── headers/               # Rate-limit headers
└── paths/                      # One directory per API area (auth/, users/, extensions/,
                                # voice/, webhooks/, platform/, ...)
```

Security schemes are defined in `openapi.yaml` under `components.securitySchemes`.

## Authentication

Each operation lists the schemes it accepts in `security`:

| Scheme | Used by |
|--------|---------|
| `bearerAuth` | Sanctum personal access token (`Authorization: Bearer <token>`) |
| `cookieAuth` | SPA session: `GET /sanctum/csrf-cookie`, then the session cookie plus `X-XSRF-TOKEN` |
| `apiKeyAuth` | Organization API key (`Authorization: Bearer opbxk_...`). Only on operations that carry `x-api-key-scope: <resource>:<read\|write>`; GET needs `read`, other methods `write`. Everything else answers API keys with 403. |
| `embedTokenAuth` | Embedded Dialer token (`opbxd_...`) for `/v1/embed/*` |
| `webhookBearer` | Cloudonix webhooks: `Authorization: Bearer <domain_requests_api_key>` of the organization named in the payload. Organizations without a key are accepted unless `CLOUDONIX_WEBHOOK_REQUIRE_AUTH=true`. No HMAC. |
| `voiceWebhookAuth` | Voice routing and voice callbacks: `Authorization: Bearer <domain_requests_api_key>` (always required; failures answer 401 CXML) |
| `dialerWorkerAuth` | Dialer worker (`DIALER_WORKER_API_TOKEN`, or `DIALER_WORKER_API_TOKEN_SECONDARY`) |
| `amdWorkerAuth` | AMD worker (`AMD_WORKER_API_TOKEN`) |

Public operations have `security: []` (for example login, registration, the public status
page, the public queues wallboard, signed recording URLs and HMAC-signed queue callbacks).

## Usage

View or test the API by importing `openapi.yaml` into Swagger Editor, Postman (OpenAPI 3.1)
or Redoc. Validate it with the Redocly command under [Validation](#validation).

## API Overview

### Control Plane (Authenticated)

| Resource | Endpoints |
|----------|-----------|
| **Health** | `GET /health`, `GET /storage/health`, `GET /websocket/health` |
| **Auth** | Login, logout, refresh, register, me, Auth0 social login |
| **Profile** | Get/update profile, password, organization |
| **Organizations** | Join requests (submit, list, approve, reject) |
| **Users** | CRUD for organization users, invitations, password, delete-impact preview, per-user Embedded Dialer token |
| **API Keys** | Owner-managed scoped API keys |
| **Extensions** | CRUD + sync + password management |
| **Conference Rooms** | CRUD for conference rooms |
| **Ring Groups** | CRUD for ring groups |
| **Call Queues** | CRUD + toggle status, agent state, live view, statistics, queue call history and export, public wallboard link |
| **Business Hours** | CRUD + duplicate + toggle status |
| **IVR Menus** | CRUD + voices + toggle status |
| **Phone Numbers** | DID management |
| **AI Assistants** | AI assistant configuration and providers |
| **AI Load Balancers** | Load balancer configuration |
| **Call Detail Records** | CDR records, statistics, export and recording |
| **Call Tracking** | Campaigns, numbers, DNI, sessions, analytics, notifications, ad-platform integrations |
| **Recordings** | Recording management and download |
| **Inbound Blacklist** | Blocked caller management |
| **Outbound Whitelist** | Outbound dialing restrictions |
| **Settings** | Cloudonix settings |
| **Trunks** | SIP trunk management (proxied from Cloudonix) |
| **Session Updates** | Real-time call session management + call coaching (spy/whisper/barge) |
| **Supervisors** | Supervisor assignments + supervisor dashboard |
| **Web Phone** | In-browser softphone SIP provisioning + recent calls log (any role with a user extension) |
| **Embedded Dialer** | Token-authenticated widget runtime API (config + calls log) for embedding the Web Phone on third-party sites |
| **Call Notifications** | Webhook notification settings, logs and test |
| **Auto Dialer** | Campaign management, monitoring, lifecycle |
| **Distribution Lists** | List upload, assignment, management |
| **Configuration** | Public application configuration |
| **Dialer Worker** | Internal API for the Go dialer worker |

### Execution Plane (Webhooks)

| Endpoint | Purpose |
|----------|---------|
| `POST /webhooks/cloudonix/call-initiated` | Async call notification |
| `POST /webhooks/cloudonix/call-status` | Call status updates |
| `POST /webhooks/cloudonix/cdr` | Call detail records |
| `POST /webhooks/cloudonix/session-update` | Session updates (high velocity) |
| `POST /webhooks/cloudonix/recording-status` | Recording status |
| `POST /webhooks/cloudonix/dialer` | Auto-dialer webhook proxy |
| `POST /webhooks/auto-dialer/call-status` | Auto-dialer call status |
| `POST /webhooks/auto-dialer/amd-result` | Auto-dialer AMD result |

### Voice Routing (CXML)

| Endpoint | Purpose |
|----------|---------|
| `POST /voice/route` | Main inbound call routing (returns CXML) |
| `POST /voice/ivr-input` | IVR digit input handling |
| `GET /voice/health` | Voice routing health |
| `POST /voice/amd-session`, `POST /voice/amd-action` | AMD worker session check and detection result |
| `POST /callbacks/voice/ring-group-callback` | Ring group routing |
| `POST /callbacks/voice/conference-pin` | Conference room PIN entry |
| `POST /callbacks/voice/albs-follow-through` | AI load balancer failover routing |
| `/callbacks/voice/queue-*` | Call queue polling, dialing and conference callbacks |

### Platform Manager

| Resource | Endpoints |
|----------|-----------|
| **Dashboard** | `GET /v1/platform/dashboard` - cross-tenant counts, recent organizations and audit entries |
| **Organizations** | List, show, update settings, change status (active/suspended/deleted), list and create users |
| **Operate As** | `POST /v1/platform/operate-as/{organization}`, `DELETE /v1/platform/operate-as` - act as an organization's Owner |
| **Users** | Cross-tenant list/show/update/delete, delete-impact preview, password reset, platform-manager flag |
| **Audit Logs** | `GET /v1/platform/audit-logs` - filterable platform audit trail |
| **CDR Archive** | `GET /v1/platform/cdr-archive` - warm-archive CDRs across organizations |
| **Status** | `GET /v1/platform/status`, `POST /v1/platform/status/check` (plus the public `GET /v1/public/status`) |

All platform routes require a Sanctum user with the platform-manager flag
(`EnsurePlatformManager`); API keys are not accepted.

## Rate Limiting

| Scope | Limit |
|-------|-------|
| Tenant API (`/v1/*` routes with the per-organization limiter) | 60 requests/minute per organization (`RATE_LIMIT_API`); responses carry `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` |
| Profile password and organization updates | 10 requests/minute per user (`RATE_LIMIT_SENSITIVE`) |
| Extension password view and reset | 10 requests/minute per user and IP; 60 for Owner and PBX Admin (`RATE_LIMIT_SENSITIVE_ADMIN`) |
| Login, Auth0 redirect/callback, join requests, invitation validate/accept | 5 requests/minute per IP (`RATE_LIMIT_AUTH`) |
| Registration | 10 requests/hour per IP |
| Email validation | 10 requests/minute per IP |
| Embedded Dialer API | 60 requests/minute per token and IP |
| Call tracking DNI swap | 60 requests/minute per organization and IP |
| Public status / public queues wallboard / platform status check | 60 / 120 / 6 requests/minute |
| Dialer worker API | 1000 requests/minute per IP (`DIALER_WORKER_RATE_LIMIT`) |
| Cloudonix webhooks, voice routing and voice callbacks | Not rate-limited |

Routes without a limiter in this table (for example auto-dialer campaigns, distribution
lists and session updates) are not rate-limited. A limited request answers 429 with
`{"error", "message", "retry_after"}` and a `Retry-After` header.

## Response Format

### Success Response
Single resources are usually returned as `{"data": {...}}`, sometimes with a `message`.
The exact shape is documented on each operation.

### Error Response
Authentication, authorization and HTTP exceptions:
```json
{ "error": "Unauthenticated", "message": "Authentication required to access this resource." }
```
Controller errors:
```json
{ "error": { "code": "CREATION_ERROR", "message": "Human-readable error message", "details": null } }
```
API-key scope denials and tenant-scope checks return only `{"message": "..."}`.

### Validation Error (422)
```json
{
  "message": "The name field is required. (and 1 more error)",
  "errors": {
    "name": ["The name field is required."],
    "email": ["The email field must be a valid email address."]
  }
}
```

## Pagination

Most list endpoints return:

```json
{
  "data": [...],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 150,
    "last_page": 8,
    "from": 1,
    "to": 20
  }
}
```

Some endpoints return a Laravel resource collection instead (a top-level `links` object and
a `meta` that also has `path` and `links`); each operation shows its shape.

Common query parameters:
- `page` - Page number (default: 1)
- `per_page` - Items per page (usually 1-100, default 20; most endpoints clamp out-of-range values, some answer 422)
- `sort_by` - Sort field; values that are not allowed fall back to the endpoint's default
- `sort_order` - `asc` or `desc`; the default depends on the endpoint

## Documentation Standards

All endpoint documentation is derived directly from source code:
- **Routes**: `routes/api.php`, `routes/webhooks.php`, `routes/platform.php`
- **Controllers**: `app/Http/Controllers/`
- **Requests**: `app/Http/Requests/` (validation rules)
- **Resources**: `app/Http/Resources/` (response structures)
- **Models**: `app/Models/` (field definitions)
- **Enums**: `app/Enums/` (enum values)

## Versioning

Current API version: **v1**

Control-plane endpoints are under `/api/v1`. Health checks (`/api/health`,
`/api/storage/health`, `/api/websocket/health`), `/api/sanctum/csrf-cookie`,
`/api/storage/recordings/{path}`, Cloudonix webhooks (`/api/webhooks/...`) and voice
routing (`/api/voice/...`, `/api/callbacks/voice/...`) are not versioned.

## Security

- Authenticated endpoints require a Sanctum token or session cookie (or an API key / Embedded Dialer token where the operation allows it)
- Cloudonix webhooks use the organization's `domain_requests_api_key` as a Bearer token (no HMAC)
- Voice routing uses the same organization-specific Bearer token
- Platform endpoints require the platform manager flag
- API keys reach only the operations whose resource they were granted (`x-api-key-scope`)

## License

This documentation is part of the OPBX open-source project and is licensed under the MIT License.
