## 8. Testing Plan

### 8.1 Test Categories

| Category | Count | Priority | Phase |
|---|---|---|---|
| Unit — OrganizationScope bypass | 5 | P0 | 7 |
| Unit — EnsurePlatformManager middleware | 4 | P0 | 7 |
| Unit — User model PM methods | 4 | P0 | 7 |
| Feature — Dashboard endpoint | 4 | P1 | 7 |
| Feature — Organization endpoints | 12 | P0 | 7 |
| Feature — User endpoints | 13 | P0 | 7 |
| Feature — Audit log endpoint | 7 | P1 | 7 |
| Feature — Artisan commands | 7 | P0 | 7 |
| Regression — Existing tests | 1 | P0 | 7 |
| Frontend — Type check | 1 | P0 | 7 |
| Frontend — Lint | 1 | P0 | 7 |
| **Total** | **59** | | |

### 8.2 Test Data Setup

All platform feature tests should use a common test setup trait:

```php
<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Models\Organization;
use App\Models\User;

trait CreatesPlatformTestData
{
    protected User $platformManager;
    protected User $regularUser;
    protected Organization $managerOrg;
    protected Organization $otherOrg;

    protected function setUpPlatformTestData(): void
    {
        $this->managerOrg = Organization::factory()->create([
            'name' => 'Manager Org',
            'slug' => 'manager-org',
            'status' => 'active',
        ]);

        $this->platformManager = User::factory()->create([
            'organization_id' => $this->managerOrg->id,
            'name' => 'Platform Manager',
            'email' => 'pm@example.com',
            'role' => 'owner',
        ]);
        $this->platformManager->is_platform_manager = true;
        $this->platformManager->save();

        $this->otherOrg = Organization::factory()->create([
            'name' => 'Other Org',
            'slug' => 'other-org',
            'status' => 'active',
        ]);

        $this->regularUser = User::factory()->create([
            'organization_id' => $this->otherOrg->id,
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'role' => 'owner',
        ]);
    }
}
```

### 8.3 Detailed Test Specifications

#### Unit Tests: OrganizationScope Bypass (`tests/Unit/Scopes/OrganizationScopeBypassTest.php`)

```
test_bypass_allows_cross_org_queries
  - Create 2 orgs with users
  - Authenticate as user in org 1
  - Without bypass: User::all() returns only org 1 users
  - With bypass: User::all() returns users from both orgs

test_scope_applies_normally_outside_bypass
  - Create 2 orgs with users
  - Authenticate as user in org 1
  - After bypass callback completes: User::all() returns only org 1 users

test_nested_bypass_calls_work_correctly
  - Nest two bypass calls
  - After inner bypass returns, scope is still bypassed (counter = 1)
  - After outer bypass returns, scope is restored (counter = 0)

test_bypass_restores_scope_after_exception
  - Throw exception inside bypass callback
  - Catch exception
  - Verify scope is restored (isBypassed() returns false)

test_is_bypassed_returns_correct_state
  - Outside bypass: isBypassed() returns false
  - Inside bypass: isBypassed() returns true
  - After bypass: isBypassed() returns false
```

#### Unit Tests: EnsurePlatformManager Middleware (`tests/Unit/Middleware/EnsurePlatformManagerTest.php`)

```
test_allows_platform_manager_through
  - Create PM user, authenticate, send request through middleware
  - Assert response proceeds (not 403)

test_blocks_non_platform_manager
  - Create regular user (is_platform_manager=false), authenticate
  - Assert 403 response with correct message

test_blocks_unauthenticated_request
  - Send request with no auth
  - Assert 403 response (or 401 — depends on middleware order)

test_blocks_user_with_false_flag
  - Explicitly set is_platform_manager=false
  - Assert 403 response
```

#### Feature Tests: Platform Organization (`tests/Feature/Platform/PlatformOrganizationTest.php`)

```
test_can_list_all_organizations
  - Create 3 orgs across system
  - PM lists orgs → gets all 3
  - Each org has correct counts

test_can_search_organizations_by_name
  - Create orgs named "Alpha", "Beta", "Gamma"
  - Search "alph" → returns Alpha only

test_can_filter_organizations_by_status
  - Create active, suspended, deleted orgs
  - Filter status=suspended → returns only suspended

test_can_sort_organizations
  - Create orgs with different dates
  - Sort by created_at asc → verify order
  - Sort by name desc → verify order

test_pagination_works
  - Create 30 orgs
  - Request page=2, per_page=10 → returns 10 items, correct meta

test_can_view_organization_detail
  - Create org with 5 users, 10 extensions, 3 DIDs
  - GET detail → correct counts, user list present

test_can_update_organization_settings
  - Update org name and timezone
  - Verify response has new values
  - Verify audit log created with before/after

test_can_change_organization_status_to_suspended
  - Active org → PATCH status=suspended
  - Verify 200, status is suspended
  - Verify audit log with reason

test_can_change_organization_status_to_active
  - Suspended org → PATCH status=active
  - Verify 200, status is active

test_can_soft_delete_organization
  - Active org → PATCH status=deleted
  - Verify status is deleted, deleted_at is set

test_cannot_delete_already_deleted_organization
  - Deleted org → PATCH status=deleted
  - Verify 422 error

test_non_platform_manager_cannot_access
  - Regular user (not PM) hits each endpoint
  - All return 403
```

#### Feature Tests: Platform User (`tests/Feature/Platform/PlatformUserTest.php`)

```
test_can_list_all_users_across_organizations
  - Create users in 3 different orgs
  - PM lists all users → gets all of them
  - Each user includes organization_name

test_can_filter_users_by_organization
  - List users with organization_id filter
  - Returns only users from that org

test_can_list_users_for_specific_organization
  - GET /organizations/{id}/users
  - Returns only that org's users

test_can_create_user_in_any_organization
  - POST new user to org PM doesn't belong to
  - User created with correct organization_id
  - Audit log created

test_can_update_user_in_any_organization
  - Update user name and role in different org
  - Verify changes saved
  - Audit log with before/after

test_can_delete_user_in_any_organization
  - Delete user in different org
  - Verify user deleted
  - Audit log created

test_cannot_delete_self
  - PM tries to delete own user ID
  - Returns 403

test_cannot_delete_last_owner_of_organization
  - Org has single owner user
  - Try to delete that user → 422

test_can_set_platform_manager_flag
  - PATCH user with is_platform_manager=true
  - Verify flag is set
  - Audit log with action=user.platform_manager.granted

test_can_revoke_platform_manager_flag
  - Create second PM
  - Revoke flag on one → success
  - Audit log with action=user.platform_manager.revoked

test_cannot_revoke_last_platform_manager
  - Only one PM in system
  - Try to revoke → 422 error

test_user_creation_creates_audit_log
  - Create user → verify audit log entry exists with correct action

test_normal_user_crud_does_not_expose_platform_manager_flag
  - Use regular tenant-scoped user update endpoint
  - Send is_platform_manager=true in body
  - Verify flag is NOT changed on the user
```

#### Feature Tests: Artisan Commands (`tests/Feature/Commands/PlatformManagerCommandsTest.php`)

```
test_set_platform_manager_sets_flag_on_existing_user
  - Create regular user
  - Run opbx:set-platform-manager {email}
  - Verify is_platform_manager=true
  - Assert exit code 0

test_set_platform_manager_fails_for_nonexistent_user
  - Run with nonexistent email
  - Assert error output
  - Assert exit code 1

test_set_platform_manager_is_idempotent
  - User already PM
  - Run set command again
  - Assert notice message
  - Assert exit code 0

test_revoke_platform_manager_clears_flag
  - Create 2 PMs
  - Revoke one
  - Verify is_platform_manager=false
  - Assert exit code 0

test_revoke_platform_manager_fails_for_nonexistent_user
  - Run with nonexistent email
  - Assert error output
  - Assert exit code 1

test_revoke_platform_manager_refuses_to_revoke_last_manager
  - Only 1 PM in system
  - Run revoke on that user
  - Assert error: "Cannot revoke the last platform manager"
  - Assert exit code 1
  - Assert flag is still true

test_create_platform_manager_creates_user_and_org
  - Run interactive command with test input
  - Provide: new email, name, password, new org name
  - Assert user created with is_platform_manager=true
  - Assert organization created
  - Assert exit code 0
```

---

