# Users Management API Reference

## Base URL
```
/api/v1/users
```

## Authentication
All endpoints require authentication via Laravel Sanctum.

Include the bearer token in the request header:
```
Authorization: Bearer {token}
```

## Permissions
- Owner: Full access to all user management operations
- PBX Admin: Can manage PBX User and Reporter only
- PBX User: No access
- Reporter: No access

---

## Endpoints

### 1. List Users

**GET** `/api/v1/users`

Returns a paginated list of users in the current user's organization.

#### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `page` | integer | No | 1 | Page number |
| `per_page` | integer | No | 25 | Items per page (1-100) |
| `role` | string | No | - | Filter by role (owner, pbx_admin, pbx_user, reporter) |
| `status` | string | No | - | Filter by status (active, inactive) |
| `search` | string | No | - | Search by name or email |
| `sort` | string | No | created_at | Sort field (name, email, created_at, role, status) |
| `order` | string | No | desc | Sort order (asc, desc) |

#### Response: 200 OK

```json
{
  "data": [
    {
      "id": 1,
      "organization_id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "role": "pbx_admin",
      "status": "active",
      "phone": "+1234567890",
      "street_address": "123 Main St",
      "city": "New York",
      "state_province": "NY",
      "postal_code": "10001",
      "country": "USA",
      "extension": {
        "id": 1,
        "user_id": 1,
        "extension_number": "101"
      },
      "created_at": "2024-01-15T10:00:00Z",
      "updated_at": "2024-01-15T10:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 100,
    "last_page": 4,
    "from": 1,
    "to": 25
  }
}
```

#### Error Responses

- **401 Unauthorized**: Not authenticated
- **403 Forbidden**: Insufficient permissions

---

### 2. Create User

**POST** `/api/v1/users`

Creates a new user in the current user's organization.

#### Request Body

```json
{
  "name": "Jane Smith",
  "email": "jane@example.com",
  "password": "SecurePass123",
  "role": "pbx_user",
  "status": "active",
  "phone": "+1234567890",
  "street_address": "456 Oak Ave",
  "city": "Los Angeles",
  "state_province": "CA",
  "postal_code": "90001",
  "country": "USA"
}
```

#### Required Fields

| Field | Type | Constraints |
|-------|------|-------------|
| `name` | string | 2-255 characters |
| `email` | string | Valid email, unique within organization |
| `password` | string | Min 8 chars, 1 uppercase, 1 lowercase, 1 number |
| `role` | string | owner, pbx_admin, pbx_user, reporter |

#### Optional Fields

| Field | Type | Constraints |
|-------|------|-------------|
| `status` | string | active, inactive (default: active) |
| `phone` | string | Max 50 characters |
| `street_address` | string | Max 255 characters |
| `city` | string | Max 100 characters |
| `state_province` | string | Max 100 characters |
| `postal_code` | string | Max 20 characters |
| `country` | string | Max 100 characters |

#### Response: 201 Created

```json
{
  "message": "User created successfully.",
  "user": {
    "id": 2,
    "organization_id": 1,
    "name": "Jane Smith",
    "email": "jane@example.com",
    "role": "pbx_user",
    "status": "active",
    "phone": "+1234567890",
    "street_address": "456 Oak Ave",
    "city": "Los Angeles",
    "state_province": "CA",
    "postal_code": "90001",
    "country": "USA",
    "extension": null,
    "created_at": "2024-01-15T11:00:00Z",
    "updated_at": "2024-01-15T11:00:00Z"
  }
}
```

#### Error Responses

- **401 Unauthorized**: Not authenticated
- **403 Forbidden**: Insufficient permissions (PBX Admin trying to create Owner/PBX Admin)
- **422 Unprocessable Entity**: Validation errors
- **500 Internal Server Error**: Server error

#### Validation Error Example

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": [
      "This email address is already in use within your organization."
    ],
    "password": [
      "Password must be at least 8 characters."
    ]
  }
}
```

---

### 3. Get User Details

**GET** `/api/v1/users/{id}`

Returns details for a specific user.

#### URL Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | integer | Yes | User ID |

#### Response: 200 OK

```json
{
  "user": {
    "id": 1,
    "organization_id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "role": "pbx_admin",
    "status": "active",
    "phone": "+1234567890",
    "street_address": "123 Main St",
    "city": "New York",
    "state_province": "NY",
    "postal_code": "10001",
    "country": "USA",
    "extension": {
      "id": 1,
      "user_id": 1,
      "extension_number": "101"
    },
    "created_at": "2024-01-15T10:00:00Z",
    "updated_at": "2024-01-15T10:00:00Z"
  }
}
```

#### Error Responses

- **401 Unauthorized**: Not authenticated
- **403 Forbidden**: Insufficient permissions
- **404 Not Found**: User not found or not in your organization

---

### 4. Update User

**PUT** `/api/v1/users/{id}` or **PATCH** `/api/v1/users/{id}`

Updates an existing user. All fields are optional - only send the fields you want to update.

#### URL Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | integer | Yes | User ID |

#### Request Body

```json
{
  "name": "John Updated",
  "email": "john.updated@example.com",
  "password": "NewSecurePass123",
  "role": "pbx_admin",
  "status": "inactive",
  "phone": "+1987654321",
  "city": "Boston"
}
```

#### Field Constraints

- Same as Create User endpoint
- `password` is optional (only include if changing)
- `email` uniqueness check excludes current user

#### Response: 200 OK

```json
{
  "message": "User updated successfully.",
  "user": {
    "id": 1,
    "organization_id": 1,
    "name": "John Updated",
    "email": "john.updated@example.com",
    "role": "pbx_admin",
    "status": "inactive",
    "phone": "+1987654321",
    "street_address": "123 Main St",
    "city": "Boston",
    "state_province": "NY",
    "postal_code": "10001",
    "country": "USA",
    "extension": {
      "id": 1,
      "user_id": 1,
      "extension_number": "101"
    },
    "created_at": "2024-01-15T10:00:00Z",
    "updated_at": "2024-01-15T12:00:00Z"
  }
}
```

#### Error Responses

- **401 Unauthorized**: Not authenticated
- **403 Forbidden**:
  - Insufficient permissions
  - Cannot manage this user (role hierarchy)
  - Trying to change own role
- **404 Not Found**: User not found or not in your organization
- **422 Unprocessable Entity**: Validation errors
- **500 Internal Server Error**: Server error

#### Business Rules

- Cannot change your own role
- PBX Admin cannot modify Owner or PBX Admin users
- PBX Admin can only set role to pbx_user or reporter

---

### 5. Delete User

**DELETE** `/api/v1/users/{id}`

Permanently deletes a user.

#### URL Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | integer | Yes | User ID |

#### Response: 204 No Content

Empty response body on success.

#### Error Responses

- **401 Unauthorized**: Not authenticated
- **403 Forbidden**:
  - Insufficient permissions
  - Cannot manage this user (role hierarchy)
- **404 Not Found**: User not found or not in your organization
- **409 Conflict**:
  - Cannot delete yourself
  - Cannot delete last owner in organization
- **500 Internal Server Error**: Server error

#### Business Rules

- Cannot delete yourself
- Cannot delete the last owner in the organization
- Must have permission to manage the target user

---

## Role Hierarchy Matrix

| Actor Role | Can Create | Can Update | Can Delete |
|------------|-----------|-----------|-----------|
| Owner | All roles | All users | All users (except last owner) |
| PBX Admin | pbx_user, reporter | pbx_user, reporter | pbx_user, reporter |
| PBX User | None | None | None |
| Reporter | None | None | None |

---

## Common HTTP Status Codes

| Code | Meaning | Description |
|------|---------|-------------|
| 200 | OK | Request successful |
| 201 | Created | User created successfully |
| 204 | No Content | User deleted successfully |
| 401 | Unauthorized | Not authenticated |
| 403 | Forbidden | Insufficient permissions |
| 404 | Not Found | User not found |
| 409 | Conflict | Business rule violation |
| 422 | Unprocessable Entity | Validation errors |
| 500 | Internal Server Error | Server error |

---

## Error Response Format

### Validation Errors (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": [
      "Error message 1",
      "Error message 2"
    ]
  }
}
```

### General Errors (403, 404, 409, 500)

```json
{
  "error": "Error Type",
  "message": "Human-readable error message"
}
```

---

## Examples Using cURL

### List Users

```bash
curl -X GET "https://api.example.com/api/v1/users?page=1&per_page=25&status=active" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### Create User

```bash
curl -X POST "https://api.example.com/api/v1/users" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "name": "Jane Smith",
    "email": "jane@example.com",
    "password": "SecurePass123",
    "role": "pbx_user",
    "status": "active"
  }'
```

### Update User

```bash
curl -X PUT "https://api.example.com/api/v1/users/1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "name": "Jane Smith Updated",
    "status": "inactive"
  }'
```

### Delete User

```bash
curl -X DELETE "https://api.example.com/api/v1/users/1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

---

## Notes

- All timestamps are in ISO 8601 format (UTC)
- Email addresses must be unique within an organization (not globally)
- Password is never returned in responses
- Extension relationship is included but read-only (extension management is separate)
- All operations are logged with request_id for traceability
- All queries are tenant-scoped to the authenticated user's organization
