# Platform Management API Reference

## Authentication

All platform management endpoints require authentication via Sanctum Bearer token AND platform manager privileges.

```
Authorization: Bearer {token}
```

**Note**: The token must include `platform:*` abilities. Log out and back in after being granted PM status.

---

## Dashboard

### Get Platform Statistics

```http
GET /api/v1/platform/dashboard
```

Returns platform-wide statistics including organization, user, and extension counts.

#### Response

```json
{
  "data": {
    "organizations": {
      "total": 42,
      "active": 38,
      "suspended": 3,
      "deleted": 1
    },
    "users": {
      "total": 156,
      "active": 145,
      "inactive": 8,
      "platform_managers": 3
    },
    "extensions": {
      "total": 289
    },
    "dids": {
      "total": 45
    },
    "recent_organizations": [
      {
        "id": "550e8400-e29b-41d4-a716-446655440000",
        "name": "Acme Corp",
        "slug": "acme-corp",
        "status": "active",
        "timezone": "America/New_York",
        "users_count": 12,
        "extensions_count": 24,
        "dids_count": 3,
        "created_at": "2026-03-01T12:00:00Z"
      }
    ],
    "recent_audit_logs": [
      {
        "id": 123,
        "action": "organization.status.updated",
        "platform_manager": {
          "id": "...",
          "name": "Admin User",
          "email": "admin@example.com"
        },
        "target_organization": {
          "id": "...",
          "name": "Acme Corp"
        },
        "created_at": "2026-03-01T14:30:00Z"
      }
    ]
  }
}
```

---

## Organizations

### List Organizations

```http
GET /api/v1/platform/organizations
```

#### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `search` | string | Filter by name or slug |
| `status` | string | Filter by status: `active`, `suspended`, `deleted` |
| `sort_by` | string | Sort field: `name`, `created_at`, `users_count` |
| `sort_direction` | string | `asc` or `desc` |
| `per_page` | integer | Items per page (max 100) |
| `page` | integer | Page number |

#### Response

```json
{
  "data": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "name": "Acme Corp",
      "slug": "acme-corp",
      "status": "active",
      "timezone": "America/New_York",
      "created_at": "2026-03-01T12:00:00Z",
      "updated_at": "2026-03-01T12:00:00Z",
      "users_count": 12,
      "extensions_count": 24,
      "dids_count": 3
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 25,
    "total": 120
  }
}
```

### Get Organization

```http
GET /api/v1/platform/organizations/{organization}
```

#### Response

```json
{
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Acme Corp",
    "slug": "acme-corp",
    "status": "active",
    "timezone": "America/New_York",
    "settings": {
      "cloudonix": {
        "domain_name": "acme.cloudonix.io",
        "api_key": "****1234"
      }
    },
    "users": [
      {
        "id": "...",
        "name": "John Doe",
        "email": "john@acme.com",
        "role": "owner",
        "status": "active"
      }
    ],
    "users_count": 12,
    "extensions_count": 24,
    "dids_count": 3,
    "ring_groups_count": 5,
    "business_hours_count": 2,
    "created_at": "2026-03-01T12:00:00Z",
    "updated_at": "2026-03-01T12:00:00Z"
  }
}
```

### Update Organization

```http
PUT /api/v1/platform/organizations/{organization}
```

#### Request Body

```json
{
  "name": "Acme Corporation",
  "timezone": "America/Los_Angeles",
  "settings": {
    "cloudonix": {
      "domain_name": "acme-new.cloudonix.io"
    }
  }
}
```

#### Response

```json
{
  "message": "Organization updated successfully.",
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Acme Corporation",
    "timezone": "America/Los_Angeles"
  }
}
```

### Update Organization Status

```http
PATCH /api/v1/platform/organizations/{organization}/status
```

#### Request Body

```json
{
  "status": "suspended",
  "reason": "Payment overdue - 30 days"
}
```

#### Status Values

| Status | Description |
|--------|-------------|
| `active` | Organization fully operational |
| `suspended` | Organization temporarily disabled |
| `deleted` | Organization soft-deleted |

#### Status Transitions

```
active → suspended ✓
active → deleted ✓
suspended → active ✓
suspended → deleted ✓
deleted → * ✗
```

#### Response

```json
{
  "message": "Organization status updated successfully.",
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "status": "suspended",
    "previous_status": "active"
  }
}
```

---

## Users

### List Users

```http
GET /api/v1/platform/users
```

#### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `search` | string | Filter by name or email |
| `organization_id` | string | Filter by organization UUID |
| `role` | string | Filter by role: `owner`, `pbx_admin`, `pbx_user`, `reporter` |
| `status` | string | Filter by status: `active`, `inactive`, `suspended` |
| `is_platform_manager` | boolean | Filter by PM status |
| `sort_by` | string | Sort field: `name`, `email`, `created_at` |
| `sort_direction` | string | `asc` or `desc` |
| `per_page` | integer | Items per page (max 100) |

#### Response

```json
{
  "data": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440001",
      "organization_id": "550e8400-e29b-41d4-a716-446655440000",
      "name": "John Doe",
      "email": "john@acme.com",
      "role": "owner",
      "status": "active",
      "is_platform_manager": false,
      "created_at": "2026-03-01T12:00:00Z",
      "organization": {
        "id": "550e8400-e29b-41d4-a716-446655440000",
        "name": "Acme Corp",
        "slug": "acme-corp"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 7,
    "per_page": 25,
    "total": 156
  }
}
```

### Get User

```http
GET /api/v1/platform/users/{user}
```

#### Response

```json
{
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440001",
    "organization_id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "John Doe",
    "email": "john@acme.com",
    "role": "owner",
    "status": "active",
    "is_platform_manager": false,
    "created_at": "2026-03-01T12:00:00Z",
    "updated_at": "2026-03-01T12:00:00Z",
    "organization": {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "name": "Acme Corp",
      "slug": "acme-corp"
    }
  }
}
```

### Set Platform Manager Status

```http
PATCH /api/v1/platform/users/{user}/platform-manager
```

#### Request Body

```json
{
  "is_platform_manager": true
}
```

#### Response (Grant)

```json
{
  "message": "Platform manager status granted.",
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440001",
    "is_platform_manager": true
  }
}
```

#### Response (Revoke)

```json
{
  "message": "Platform manager status revoked. Tokens invalidated.",
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440001",
    "is_platform_manager": false
  }
}
```

**Important**: Revoking PM status invalidates all of the user's tokens.

---

## Audit Logs

### List Audit Logs

```http
GET /api/v1/platform/audit-logs
```

#### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `platform_manager_user_id` | string | Filter by PM user UUID |
| `action` | string | Filter by action type |
| `target_organization_id` | string | Filter by organization UUID |
| `per_page` | integer | Items per page (max 100) |
| `page` | integer | Page number |

#### Action Types

| Action | Description |
|--------|-------------|
| `user.create` | User created |
| `user.update` | User updated |
| `user.delete` | User deleted |
| `user.platform_manager.granted` | PM status granted |
| `user.platform_manager.revoked` | PM status revoked |
| `organization.create` | Organization created |
| `organization.update` | Organization updated |
| `organization.status.updated` | Organization status changed |

#### Response

```json
{
  "data": [
    {
      "id": 123,
      "platform_manager_user_id": "550e8400-e29b-41d4-a716-446655440002",
      "platform_manager": {
        "id": "550e8400-e29b-41d4-a716-446655440002",
        "name": "Admin User",
        "email": "admin@example.com"
      },
      "target_organization_id": "550e8400-e29b-41d4-a716-446655440000",
      "target_organization": {
        "id": "550e8400-e29b-41d4-a716-446655440000",
        "name": "Acme Corp",
        "slug": "acme-corp"
      },
      "action": "organization.status.updated",
      "target_entity_type": "Organization",
      "target_entity_id": "550e8400-e29b-41d4-a716-446655440000",
      "before_state": {
        "status": "active"
      },
      "after_state": {
        "status": "suspended"
      },
      "reason": "Payment overdue",
      "ip_address": "192.168.1.100",
      "user_agent": "Mozilla/5.0...",
      "created_at": "2026-03-01T14:30:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 10,
    "per_page": 25,
    "total": 245
  }
}
```

---

## Error Responses

### 401 Unauthorized

```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden

```json
{
  "message": "Platform manager access required."
}
```

### 404 Not Found

```json
{
  "message": "Organization not found."
}
```

### 422 Unprocessable Entity

```json
{
  "message": "Cannot revoke the last platform manager.",
  "errors": {
    "status": ["Invalid status transition."]
  }
}
```

---

## Rate Limiting

Platform management endpoints have stricter rate limits:

| Endpoint | Limit |
|----------|-------|
| All endpoints | 60 requests/minute |
| Status changes | 30 requests/minute |
| User updates | 30 requests/minute |

---

## Pagination

All list endpoints support cursor-style pagination:

```http
GET /api/v1/platform/organizations?page=2&per_page=50
```

Maximum `per_page` is 100.

---

## Examples

### Suspend Organization and Verify

```bash
# Suspend organization
curl -X PATCH \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status": "suspended", "reason": "Payment overdue"}' \
  https://api.example.com/api/v1/platform/organizations/123/status

# Verify in audit log
curl "https://api.example.com/api/v1/platform/audit-logs?action=organization.status.updated" \
  -H "Authorization: Bearer $TOKEN"
```

### Promote User to Platform Manager

```bash
# Grant PM status
curl -X PATCH \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"is_platform_manager": true}' \
  https://api.example.com/api/v1/platform/users/456/platform-manager

# Verify user appears in PM list
curl "https://api.example.com/api/v1/platform/users?is_platform_manager=1" \
  -H "Authorization: Bearer $TOKEN"
```
