## 6. API Reference

### Authentication

All platform API endpoints require:
1. Valid Sanctum authentication (`auth:sanctum` middleware)
2. `is_platform_manager = true` on the authenticated user (`platform.manager` middleware)

Missing authentication returns `401 Unauthorized`. Non-platform-manager returns `403 Forbidden`.

---

### `GET /api/v1/platform/dashboard`

**Description:** Returns platform-wide statistics and recent activity.

**Request:**
```http
GET /api/v1/platform/dashboard HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
```

**Response `200 OK`:**
```json
{
  "data": {
    "organizations": {
      "total": 47,
      "active": 42,
      "suspended": 3,
      "deleted": 2
    },
    "users": {
      "total": 312,
      "active": 289,
      "inactive": 23,
      "platform_managers": 2
    },
    "extensions": {
      "total": 1580
    },
    "dids": {
      "total": 245
    },
    "recent_organizations": [
      {
        "id": 47,
        "name": "Acme Corp",
        "slug": "acme-corp",
        "status": "active",
        "timezone": "America/New_York",
        "users_count": 12,
        "extensions_count": 45,
        "dids_count": 8,
        "created_at": "2026-02-28T14:30:00Z",
        "updated_at": "2026-02-28T14:30:00Z"
      }
    ],
    "recent_audit_logs": [
      {
        "id": 156,
        "platform_manager_user_id": 1,
        "platform_manager": {
          "id": 1,
          "name": "Admin User",
          "email": "admin@example.com"
        },
        "target_organization_id": 12,
        "target_organization": {
          "id": 12,
          "name": "Beta Inc",
          "slug": "beta-inc"
        },
        "action": "organization.status.updated",
        "target_entity_type": "Organization",
        "target_entity_id": 12,
        "before_state": { "status": "active" },
        "after_state": { "status": "suspended" },
        "reason": "Payment overdue",
        "ip_address": "192.168.1.100",
        "user_agent": "Mozilla/5.0...",
        "created_at": "2026-02-28T15:00:00Z"
      }
    ]
  }
}
```

**Error Responses:**
- `401 Unauthorized` — Not authenticated
- `403 Forbidden` — Not a platform manager

---

### `GET /api/v1/platform/organizations`

**Description:** List all organizations with counts and filtering.

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `search` | string | No | — | Search by name or slug (partial match) |
| `status` | string | No | — | Filter by status: `active`, `suspended`, `deleted` |
| `sort_by` | string | No | `created_at` | Sort column: `name`, `created_at`, `users_count` |
| `sort_dir` | string | No | `desc` | Sort direction: `asc`, `desc` |
| `page` | int | No | 1 | Page number |
| `per_page` | int | No | 25 | Items per page (max 100) |

**Request:**
```http
GET /api/v1/platform/organizations?search=acme&status=active&sort_by=name&sort_dir=asc&page=1&per_page=10 HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
```

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 5,
      "name": "Acme Corp",
      "slug": "acme-corp",
      "status": "active",
      "timezone": "America/New_York",
      "settings": {},
      "users_count": 12,
      "extensions_count": 45,
      "dids_count": 8,
      "created_at": "2026-01-15T10:00:00Z",
      "updated_at": "2026-02-20T08:00:00Z",
      "deleted_at": null
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 10,
    "total": 1
  }
}
```

---

### `GET /api/v1/platform/organizations/{id}`

**Description:** Get full details of a specific organization.

**Request:**
```http
GET /api/v1/platform/organizations/5 HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
```

**Response `200 OK`:**
```json
{
  "data": {
    "id": 5,
    "name": "Acme Corp",
    "slug": "acme-corp",
    "status": "active",
    "timezone": "America/New_York",
    "settings": {
      "max_extensions": 100,
      "features": ["voicemail", "call_recording"]
    },
    "users_count": 12,
    "extensions_count": 45,
    "dids_count": 8,
    "ring_groups_count": 3,
    "business_hours_count": 2,
    "users": [
      {
        "id": 10,
        "name": "John Owner",
        "email": "john@acme.com",
        "role": "owner",
        "status": "active",
        "is_platform_manager": false,
        "created_at": "2026-01-15T10:00:00Z"
      },
      {
        "id": 11,
        "name": "Jane Admin",
        "email": "jane@acme.com",
        "role": "pbx_admin",
        "status": "active",
        "is_platform_manager": false,
        "created_at": "2026-01-16T09:00:00Z"
      }
    ],
    "created_at": "2026-01-15T10:00:00Z",
    "updated_at": "2026-02-20T08:00:00Z",
    "deleted_at": null
  }
}
```

**Error Responses:**
- `404 Not Found` — Organization not found

---

### `PUT /api/v1/platform/organizations/{id}`

**Description:** Update organization settings (name, timezone, settings).

**Request:**
```http
PUT /api/v1/platform/organizations/5 HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json

{
  "name": "Acme Corporation",
  "timezone": "America/Chicago",
  "settings": {
    "max_extensions": 200,
    "features": ["voicemail", "call_recording", "ivr"]
  }
}
```

**Response `200 OK`:**
```json
{
  "data": {
    "id": 5,
    "name": "Acme Corporation",
    "slug": "acme-corp",
    "status": "active",
    "timezone": "America/Chicago",
    "settings": {
      "max_extensions": 200,
      "features": ["voicemail", "call_recording", "ivr"]
    },
    "created_at": "2026-01-15T10:00:00Z",
    "updated_at": "2026-03-01T12:00:00Z",
    "deleted_at": null
  }
}
```

**Error Responses:**
- `404 Not Found` — Organization not found
- `422 Unprocessable Entity` — Validation errors

---

### `PATCH /api/v1/platform/organizations/{id}/status`

**Description:** Change organization status (activate, suspend, soft-delete).

**Request:**
```http
PATCH /api/v1/platform/organizations/5/status HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json

{
  "status": "suspended",
  "reason": "Payment overdue for 30 days"
}
```

**Response `200 OK`:**
```json
{
  "data": {
    "id": 5,
    "name": "Acme Corp",
    "slug": "acme-corp",
    "status": "suspended",
    "timezone": "America/New_York",
    "settings": {},
    "created_at": "2026-01-15T10:00:00Z",
    "updated_at": "2026-03-01T12:05:00Z",
    "deleted_at": null
  },
  "message": "Organization status updated to suspended."
}
```

**Error Responses:**
- `404 Not Found` — Organization not found
- `422 Unprocessable Entity` — Invalid status transition (e.g., deleted → active)

**Valid Status Transitions:**

| From | To | Description |
|---|---|---|
| `active` | `suspended` | Suspend active organization |
| `active` | `deleted` | Soft-delete active organization |
| `suspended` | `active` | Reactivate suspended organization |
| `suspended` | `deleted` | Soft-delete suspended organization |

Invalid transitions return 422 with message explaining the constraint.

---

### `GET /api/v1/platform/users`

**Description:** List all users across all organizations.

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `search` | string | No | — | Search by name or email (partial match) |
| `organization_id` | int | No | — | Filter by organization |
| `role` | string | No | — | Filter by role |
| `status` | string | No | — | Filter by status: `active`, `inactive` |
| `is_platform_manager` | boolean | No | — | Filter by PM flag |
| `sort_by` | string | No | `created_at` | Sort: `name`, `email`, `created_at` |
| `sort_dir` | string | No | `desc` | Sort direction: `asc`, `desc` |
| `page` | int | No | 1 | Page number |
| `per_page` | int | No | 25 | Items per page (max 100) |

**Request:**
```http
GET /api/v1/platform/users?search=john&organization_id=5&sort_by=name&sort_dir=asc HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
```

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 10,
      "organization_id": 5,
      "organization_name": "Acme Corp",
      "name": "John Owner",
      "email": "john@acme.com",
      "role": "owner",
      "status": "active",
      "is_platform_manager": false,
      "phone": "+1-555-0100",
      "created_at": "2026-01-15T10:00:00Z",
      "updated_at": "2026-01-15T10:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 25,
    "total": 1
  }
}
```

---

### `GET /api/v1/platform/organizations/{orgId}/users`

**Description:** List users within a specific organization.

**Query Parameters:** Same as `GET /api/v1/platform/users` except `organization_id` is implicit.

**Request:**
```http
GET /api/v1/platform/organizations/5/users HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
```

**Response:** Same structure as `GET /api/v1/platform/users`.

---

### `GET /api/v1/platform/users/{id}`

**Description:** Get details of a specific user.

**Request:**
```http
GET /api/v1/platform/users/10 HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
```

**Response `200 OK`:**
```json
{
  "data": {
    "id": 10,
    "organization_id": 5,
    "organization_name": "Acme Corp",
    "name": "John Owner",
    "email": "john@acme.com",
    "role": "owner",
    "status": "active",
    "is_platform_manager": false,
    "phone": "+1-555-0100",
    "street_address": "123 Main St",
    "city": "New York",
    "state_province": "NY",
    "postal_code": "10001",
    "country": "US",
    "email_verified_at": "2026-01-15T10:05:00Z",
    "password_last_changed_at": "2026-01-15T10:00:00Z",
    "created_at": "2026-01-15T10:00:00Z",
    "updated_at": "2026-01-15T10:00:00Z"
  }
}
```

---

### `POST /api/v1/platform/organizations/{orgId}/users`

**Description:** Create a new user in the specified organization.

**Request:**
```http
POST /api/v1/platform/organizations/5/users HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json

{
  "name": "New User",
  "email": "newuser@acme.com",
  "password": "SecurePass123!",
  "role": "pbx_user",
  "status": "active",
  "phone": "+1-555-0200"
}
```

**Response `201 Created`:**
```json
{
  "data": {
    "id": 50,
    "organization_id": 5,
    "organization_name": "Acme Corp",
    "name": "New User",
    "email": "newuser@acme.com",
    "role": "pbx_user",
    "status": "active",
    "is_platform_manager": false,
    "phone": "+1-555-0200",
    "created_at": "2026-03-01T12:00:00Z",
    "updated_at": "2026-03-01T12:00:00Z"
  },
  "message": "User created successfully."
}
```

**Error Responses:**
- `404 Not Found` — Organization not found
- `422 Unprocessable Entity` — Validation errors (duplicate email, invalid role, etc.)

---

### `PUT /api/v1/platform/users/{id}`

**Description:** Update an existing user.

**Request:**
```http
PUT /api/v1/platform/users/10 HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json

{
  "name": "John Updated",
  "role": "pbx_admin",
  "status": "active"
}
```

**Response `200 OK`:**
```json
{
  "data": {
    "id": 10,
    "organization_id": 5,
    "organization_name": "Acme Corp",
    "name": "John Updated",
    "email": "john@acme.com",
    "role": "pbx_admin",
    "status": "active",
    "is_platform_manager": false,
    "phone": "+1-555-0100",
    "created_at": "2026-01-15T10:00:00Z",
    "updated_at": "2026-03-01T12:10:00Z"
  }
}
```

---

### `DELETE /api/v1/platform/users/{id}`

**Description:** Delete a user.

**Request:**
```http
DELETE /api/v1/platform/users/10 HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
```

**Response `200 OK`:**
```json
{
  "message": "User deleted successfully."
}
```

**Error Responses:**
- `403 Forbidden` — Cannot delete yourself
- `404 Not Found` — User not found
- `422 Unprocessable Entity` — Cannot delete the last owner of an organization

---

### `PATCH /api/v1/platform/users/{id}/platform-manager`

**Description:** Set or revoke platform manager status for a user.

**Request (grant):**
```http
PATCH /api/v1/platform/users/10/platform-manager HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json

{
  "is_platform_manager": true
}
```

**Response `200 OK`:**
```json
{
  "data": {
    "id": 10,
    "organization_id": 5,
    "organization_name": "Acme Corp",
    "name": "John Owner",
    "email": "john@acme.com",
    "role": "owner",
    "status": "active",
    "is_platform_manager": true,
    "phone": "+1-555-0100",
    "created_at": "2026-01-15T10:00:00Z",
    "updated_at": "2026-03-01T12:15:00Z"
  },
  "message": "Platform manager status updated."
}
```

**Request (revoke):**
```http
PATCH /api/v1/platform/users/10/platform-manager HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json

{
  "is_platform_manager": false
}
```

**Error Responses:**
- `404 Not Found` — User not found
- `422 Unprocessable Entity` — Cannot revoke the last platform manager

```json
{
  "message": "Cannot revoke platform manager status. This is the last platform manager in the system.",
  "errors": {
    "is_platform_manager": [
      "Cannot revoke the last platform manager."
    ]
  }
}
```

---

### `GET /api/v1/platform/audit-logs`

**Description:** List platform audit log entries.

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `platform_manager_user_id` | int | No | — | Filter by acting platform manager |
| `target_organization_id` | int | No | — | Filter by target organization |
| `action` | string | No | — | Filter by action type |
| `date_from` | string (ISO 8601) | No | — | Start of date range |
| `date_to` | string (ISO 8601) | No | — | End of date range |
| `page` | int | No | 1 | Page number |
| `per_page` | int | No | 25 | Items per page (max 100) |

**Request:**
```http
GET /api/v1/platform/audit-logs?action=organization.status.updated&date_from=2026-02-01T00:00:00Z HTTP/1.1
Authorization: Bearer {token}
Accept: application/json
```

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 156,
      "platform_manager_user_id": 1,
      "platform_manager": {
        "id": 1,
        "name": "Admin User",
        "email": "admin@example.com"
      },
      "target_organization_id": 12,
      "target_organization": {
        "id": 12,
        "name": "Beta Inc",
        "slug": "beta-inc"
      },
      "action": "organization.status.updated",
      "target_entity_type": "Organization",
      "target_entity_id": 12,
      "before_state": {
        "status": "active"
      },
      "after_state": {
        "status": "suspended"
      },
      "reason": "Payment overdue",
      "ip_address": "192.168.1.100",
      "user_agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)",
      "created_at": "2026-02-28T15:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 25,
    "total": 112
  }
}
```

### Audit Action Types

The following action type strings are used in audit log entries:

| Action | Description |
|---|---|
| `organization.viewed` | Platform manager viewed organization detail |
| `organization.updated` | Organization settings/name/timezone updated |
| `organization.status.updated` | Organization status changed |
| `user.created` | User created in an organization |
| `user.updated` | User fields updated |
| `user.deleted` | User deleted |
| `user.platform_manager.granted` | Platform manager flag set to true |
| `user.platform_manager.revoked` | Platform manager flag set to false |

---

