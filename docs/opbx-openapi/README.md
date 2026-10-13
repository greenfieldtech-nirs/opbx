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
├── openapi.yaml                 # Main entry point - includes all paths and components
├── README.md                    # This file
├── components/                  # Reusable components
│   ├── schemas/                # Data models
│   │   ├── User.yaml
│   │   ├── Extension.yaml
    │   │   ├── Organization.yaml
    │   │   ├── RingGroup.yaml
    │   │   ├── ConferenceRoom.yaml
    │   │   ├── CallDetailRecord.yaml
│   │   └── ... (all entity schemas)
│   ├── parameters/             # Reusable parameters
│   │   ├── path/              # Path parameters (id, user_id, etc.)
│   │   └── query/             # Query parameters (page, per_page, sort, etc.)
│   ├── responses/             # Common responses (401, 403, 404, 422, etc.)
│   ├── headers/               # Common headers
│   └── securitySchemes/       # Authentication schemes
└── paths/                     # API endpoint definitions
    ├── health.yaml
    ├── auth/                  # Authentication endpoints
    ├── profile/               # Profile management
    ├── users/                 # User management
    ├── extensions/            # Extension management
    ├── ring-groups/           # Ring group management
    ├── conference-rooms/      # Conference room management
    ├── call-detail-records/   # CDR records
    ├── webhooks/              # Cloudonix and auto-dialer webhooks
    ├── voice/                 # Voice routing (CXML)
    └── platform/              # Platform manager endpoints
```

## Authentication

Each operation lists the schemes it accepts in `security`:

| Scheme | Used by |
|--------|---------|
| `bearerAuth` | Sanctum personal access token (`Authorization: Bearer <token>`) |
| `cookieAuth` | SPA session: `GET /sanctum/csrf-cookie`, then the session cookie plus `X-XSRF-TOKEN` |
| `apiKeyAuth` | Organization API key (`Authorization: Bearer opbxk_...`). Only on operations that carry `x-api-key-scope: <resource>:<read\|write>`; GET needs `read`, other methods `write`. Everything else answers API keys with 403. |
| `embedTokenAuth` | Embedded Dialer token (`opbxd_...`) for `/v1/embed/*` |
| `webhookBearer` | Cloudonix webhooks (Bearer or HMAC-SHA256, per organization) |
| `voiceWebhookAuth` | Voice routing callbacks (`domain_requests_api_key`) |
| `dialerWorkerAuth` | Dialer worker (`DIALER_WORKER_API_TOKEN`) |
| `amdWorkerAuth` | AMD worker (`AMD_WORKER_API_TOKEN`) |

Public operations have `security: []` (for example login, registration, the public status
page, the public queues wallboard, signed recording URLs and HMAC-signed queue callbacks).

## Usage

### Viewing the Documentation

You can view and test the API using:

1. **Swagger UI**: Import `openapi.yaml` into Swagger Editor
2. **Postman**: Import as OpenAPI 3.1 specification
3. **Redoc**: Generate documentation from `openapi.yaml`

### Validation

To validate the OpenAPI specification:

```bash
# Using swagger-cli (npm)
npm install -g swagger-cli
swagger-cli validate docs/opbx-openapi/openapi.yaml

# Using redocly
npm install -g @redocly/cli
redocly lint docs/opbx-openapi/openapi.yaml
```

## API Overview

### Control Plane (Authenticated)

| Resource | Endpoints |
|----------|-----------|
| **Health** | `GET /health`, `GET /storage/health`, `GET /websocket/health` |
| **Auth** | Login, logout, refresh, register, me, Auth0 social login |
| **Profile** | Get/update profile, password, organization |
| **Users** | CRUD operations for organization users + per-user Embedded Dialer token management |
| **Extensions** | CRUD + sync + password management |
| **Conference Rooms** | CRUD for conference rooms |
| **Ring Groups** | CRUD for ring groups |
| **Business Hours** | CRUD + duplicate + toggle status |
| **IVR Menus** | CRUD + voices + toggle status |
| **Phone Numbers** | DID management |
| **AI Assistants** | AI assistant configuration |
| **AI Load Balancers** | Load balancer configuration |
| **Call Detail Records** | CDR records and export |
| **Call Tracking** | Campaigns, DNI, sessions, analytics, ad-platform integrations |
| **Recordings** | Recording management and download |
| **Inbound Blacklist** | Blocked caller management |
| **Outbound Whitelist** | Outbound dialing restrictions |
| **Settings** | Organization and Cloudonix settings |
| **Session Updates** | Real-time call session management + call coaching (spy/whisper/barge) |
| **Supervisors** | Supervisor assignments + supervisor dashboard |
| **Web Phone** | In-browser softphone SIP provisioning + recent calls log (any authenticated role with a user-type extension) |
| **Embedded Dialer** | Token-authenticated widget runtime API (config + calls log) for embedding the Web Phone on third-party sites |
| **Call Notifications** | Webhook notification settings |

### Execution Plane (Webhooks)

| Endpoint | Purpose |
|----------|---------|
| `POST /webhooks/cloudonix/call-initiated` | Async call notification |
| `POST /webhooks/cloudonix/call-status` | Call status updates |
| `POST /webhooks/cloudonix/cdr` | Call detail records |
| `POST /webhooks/cloudonix/session-update` | Session updates (high velocity) |
| `POST /webhooks/cloudonix/dialer` | Auto-dialer webhook proxy |
| `POST /webhooks/auto-dialer/call-status` | Auto-dialer call status |
| `POST /webhooks/auto-dialer/amd-result` | Auto-dialer AMD result |

### Voice Routing (CXML)

| Endpoint | Purpose |
|----------|---------|
| `POST /voice/route` | Main inbound call routing (returns CXML) |
| `POST /voice/ivr-input` | IVR digit input handling |
| `POST /callbacks/voice/ring-group-callback` | Ring group routing |
| `POST /callbacks/voice/albs-follow-through` | ALB failover routing |

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

Rate limits are applied per organization:

| Endpoint Type | Limit |
|---------------|-------|
| API endpoints | 60 requests/minute |
| Webhooks | 100 requests/minute |
| Voice routing | 1000 requests/minute |
| Sensitive operations | 10 requests/minute |
| Authentication | 5 attempts/minute |

## Response Format

### Success Response
```json
{
  "message": "Operation completed successfully",
  "data": { ... }
}
```

### Error Response
Authentication, authorization and HTTP exceptions:
```json
{ "error": "Unauthenticated", "message": "Authentication required to access this resource." }
```
Controller errors:
```json
{ "error": { "code": "CREATION_ERROR", "message": "Human-readable error message", "details": null } }
```

### Validation Error
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field": ["Error message 1", "Error message 2"]
  }
}
```

## Pagination

List endpoints return paginated responses:

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

Query parameters:
- `page` - Page number (default: 1)
- `per_page` - Items per page (1-100, default: 20)
- `sort_by` - Sort field
- `sort_order` - asc or desc (default: asc)

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

All endpoints are prefixed with `/api/v1` (except webhooks which use their own paths).

## Security

- All authenticated endpoints require valid Sanctum token or session cookie
- Webhooks use signature verification (HMAC)
- Voice routing uses organization-specific Bearer tokens
- Platform endpoints require the platform manager flag
- API keys reach only the operations whose resource they were granted (`x-api-key-scope`)

## License

This documentation is part of the OPBX open-source project and is licensed under the MIT License.
