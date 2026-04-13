## 11. Risk Assessment

| Risk ID | Description | Likelihood | Impact | Score | Mitigation |
|---------|-------------|-----------|--------|-------|-----------|
| **RISK-01** | **Scope bypass misuse.** A future developer calls `OrganizationScope::bypass()` outside a platform context, leaking cross-tenant data. | High | Critical | 20 | (a) Method PHPDoc warns about security implications. (b) Code review checklist item. (c) Consider PHPStan rule flagging usage outside `Platform` namespace. (d) Terminate middleware resets counter as safety net. |
| **RISK-02** | **Privilege escalation via mass assignment.** `is_platform_manager` accidentally added to `$fillable`. | Low | Critical | 10 | (a) Dedicated test asserts field is not in `$fillable`. (b) `unguard()` usage flagged by CI. (c) FormRequests reject the field. |
| **RISK-03** | **Audit log gap.** New platform endpoint added without audit logging. | Medium | High | 12 | (a) Integration test asserting every non-GET platform route creates an audit record. (b) Base controller pattern with enforced audit call. |
| **RISK-04** | **Cross-org query performance.** Platform list endpoints slow on large datasets. | Medium | Medium | 9 | (a) Mandatory pagination with max 100/page. (b) Database indexes on key columns. (c) Query count assertions in tests. |
| **RISK-05** | **Production migration risk.** Adding `is_platform_manager` column causes table lock on large `users` table. | Low | High | 8 | (a) Boolean `DEFAULT FALSE` uses instant DDL in MySQL 8.0+. (b) Migration during low-traffic window. |
| **RISK-06** | **Last-PM revocation race condition.** Two concurrent revocations both pass the "more than one PM" check. | Low | Critical | 10 | (a) Pessimistic locking: `lockForUpdate()` inside transaction. (b) Redis distributed lock as backup. |
| **RISK-07** | **Token leakage with platform abilities.** PM token exposed in logs or client code. | Low | Critical | 10 | (a) Short token TTLs. (b) Token values never in audit logs. **(c) CRITICAL: Immediate token revocation when PM flag removed via `$user->tokens()->delete()`.** (d) Rate limiting. |
| **RISK-08** | **Frontend guard bypass.** Non-PM user navigates to `/ui/platform/*` directly. | Medium | Low | 6 | (a) Guard checks before rendering children. (b) API returns 403, no data exposed. (c) Cosmetic issue only. |
| **RISK-09** | **Scope bypass counter corruption in Octane.** Static counter shared across coroutines. | Low | Critical | 10 | (a) v1.0.0 does not support Octane. (b) Document as known limitation. (c) If Octane needed: use request-scoped storage. |
| **RISK-10** | **Audit log lost on transaction rollback.** Audit written inside same transaction as mutation. | Medium | Medium | 9 | (a) Write audit log outside the main transaction or on a separate DB connection. (b) Alternatively use `DB::afterCommit()` for success logging. |
| **RISK-11** | **Audit log table growth.** Unbounded table growth over months. | Low | Low | 4 | **(a) 14-day retention policy enforced via daily `opbx:cleanup-audit-logs` command.** (b) Indexed on `created_at`. (c) Pagination enforced on API. |
| **RISK-12** | **Developer confusion PM flag vs role.** Developer adds `platform_manager` to `UserRole` enum. | Medium | Medium | 9 | (a) PHPDoc on `UserRole` warns against this. (b) Test asserts exactly 4 enum cases. (c) This spec document explains the separation. |
| **RISK-13** | **Suspended organization still accessible.** Organization suspension not enforced in all middleware paths. | Medium | Critical | 15 | (a) Single `EnsureTenantScope` middleware checks org status before tenant scoping. (b) Returns 403 with "suspended" message. (c) Platform managers bypass this check entirely. (d) Test all API endpoints with suspended org user. |

### Risk Heat Map Summary

**Top Priority Risks (Score ≥ 10):** RISK-01 (20), RISK-13 (15), RISK-03 (12), RISK-02 (10), RISK-06 (10), RISK-07 (10), RISK-09 (10)

---

