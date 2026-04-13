## 12. Developer Notes & Feedback Section

### 12.1 Feedback Template

Use the following format when adding feedback during implementation:

```
> **DEV FEEDBACK (YYYY-MM-DD):** [Developer Name/Handle]
> **Phase/Task ID:** [PM-X.Y.Z]
> **Type:** [Question | Blocker | Suggestion | Resolved]
> **Message:** [Your feedback here]
```

### 12.2 Key Implementation Notes

1. **OrganizationScope Bypass — Method Name:** Use `OrganizationScope::bypass(callable)` as defined in Section 3.2.2. The method uses a counter (`$bypassCount`) wrapped in `try/finally`.

2. **Migration Column Placement:** Add `is_platform_manager` immediately after the `role` column using `->after('role')`.

3. **User Model $fillable:** Do NOT add `is_platform_manager` to `$fillable`. Add only to `$casts` as `'boolean'`.

4. **Token Revocation on PM Flag Change:** When revoking PM status, call `$user->tokens()->delete()` to force re-authentication. This is the safest approach for v1.0.0.

5. **Frontend Auth Context:** The `is_platform_manager` field is included in the User response from `/api/v1/auth/me` because it's a column not in `$hidden`. No special handling needed.

### 12.3 Common Pitfalls to Avoid

| Pitfall | Correct Approach |
|---------|-----------------|
| Adding `is_platform_manager` to `$fillable` | Keep it out; set directly on model instance |
| Manipulating bypass counter directly | Always use `OrganizationScope::bypass(callable)` |
| Forgetting `lockForUpdate()` on last-PM check | Always lock within a transaction |
| Checking `$user->role === 'platform_manager'` | Check `$user->is_platform_manager === true` |
| Putting platform routes outside `/api/v1/platform/` | All routes go in `routes/platform.php` under the group prefix |
| Middleware order `platform.manager` before `auth:sanctum` | Always: `['auth:sanctum', 'platform.manager']` |

### 12.4 Testing Tips

1. **Use `CreatesPlatformTestData` trait** (defined in Section 8.2) for all platform tests
2. **Verify bypass counter is zero** after each request in platform tests
3. **Test mass assignment** by attempting `User::create(['is_platform_manager' => true])` and asserting the flag is `false`
4. **Query count assertions** with `DB::enableQueryLog()` to catch N+1 regressions

### 12.5 Running Tests

```bash
# All platform tests
php artisan test --filter=Platform

# Specific test groups
php artisan test --filter=OrganizationScopeBypassTest
php artisan test --filter=EnsurePlatformManagerTest
php artisan test --filter=PlatformDashboardTest
php artisan test --filter=PlatformOrganizationTest
php artisan test --filter=PlatformUserTest
php artisan test --filter=PlatformAuditLogTest
php artisan test --filter=PlatformManagerCommandsTest

# Frontend checks
cd frontend && npm run type-check && npm run lint
```

### 12.6 Development Feedback Log

> **DEV FEEDBACK (YYYY-MM-DD):** [Awaiting implementation start]
> **Phase/Task ID:** —
> **Type:** —
> **Message:** This section will be populated during implementation.

---

*End of Platform Manager Feature Specification — Document ID: OPBX-FEAT-PM-001 v1.0.0*
