# Platform Management Security Checklist

This checklist ensures the Platform Management feature is properly secured before deployment.

## Pre-Deployment Checks

### Authentication & Authorization

- [ ] `EnsurePlatformManager` middleware is applied to all platform routes
- [ ] Middleware checks both authentication AND `is_platform_manager` flag
- [ ] Non-PM users receive 403 Forbidden response
- [ ] Unauthenticated users receive 401 Unauthorized response
- [ ] API tokens include `platform:*` abilities for PM users

### Mass Assignment Protection

- [ ] `is_platform_manager` is NOT in User model `$fillable` array
- [ ] Direct assignment bypass is used: `$user->is_platform_manager = true`
- [ ] Cannot set PM flag via user creation/update endpoints

### Token Management

- [ ] Revoking PM status calls `$user->revokeAllTokens()`
- [ ] User is immediately logged out when PM status is revoked
- [ ] Token abilities are checked on each request

### Last Platform Manager Protection

- [ ] System prevents revoking the last PM
- [ ] Error message: "Cannot revoke the last platform manager."
- [ ] At least 2 PM accounts exist before revoking one

### Cross-Tenant Data Access

- [ ] `OrganizationScope::bypass()` is used in all platform controllers
- [ ] Without bypass, queries are scoped to current user's organization
- [ ] PMs can view data from all organizations
- [ ] PMs cannot access data from deleted organizations

### Audit Logging

- [ ] All mutations create audit log entries
- [ ] Audit logs include before/after state
- [ ] Audit logs include IP address and user agent
- [ ] Audit logs include reason (when provided)
- [ ] Failed actions are also logged

### Data Masking

- [ ] Sensitive settings are masked in API responses
- [ ] API keys show only last 4 characters
- [ ] Passwords/tokens are never returned

## Configuration

### Environment Variables

```env
# Platform Manager Settings
PLATFORM_MANAGER_TOKEN_EXPIRY=60  # minutes
AUDIT_LOG_RETENTION_DAYS=14
```

### Scheduled Tasks

- [ ] Audit log cleanup runs daily
- [ ] Cleanup removes entries older than retention period
- [ ] Cleanup runs during off-peak hours

## Testing

### Security Tests

- [ ] `PlatformManagerSecurityTest` passes all 20 test cases
- [ ] Non-PM users cannot access platform endpoints
- [ ] PM users can access cross-tenant data
- [ ] Audit logs are created for all mutations
- [ ] Token revocation works correctly
- [ ] Last PM protection works

### Edge Cases

- [ ] Unauthenticated access is blocked
- [ ] Invalid tokens are rejected
- [ ] Expired tokens are rejected
- [ ] Malformed requests are handled gracefully

## Monitoring

### Alerts

- [ ] Alert on failed PM login attempts (>5 in 1 hour)
- [ ] Alert on organization status changes
- [ ] Alert on PM status grants/revocations
- [ ] Alert on audit log cleanup failures

### Logging

- [ ] All PM actions are logged
- [ ] Failed authorization attempts are logged
- [ ] Errors include stack traces
- [ ] Logs are retained for 30 days

## Frontend Security

### Route Protection

- [ ] `PlatformManagerRoute` guard checks PM status
- [ ] Routes redirect to error page if not PM
- [ ] Sidebar only shows Platform Management for PMs

### Data Display

- [ ] Sensitive data is masked in UI
- [ ] Confirm dialogs for destructive actions
- [ ] Success/error toasts for all actions

## Access Control Matrix

| Action | Platform Manager | Owner | Admin | User |
|--------|-----------------|-------|-------|------|
| View Dashboard | ✅ | ❌ | ❌ | ❌ |
| List Organizations | ✅ | ❌ | ❌ | ❌ |
| View Organization | ✅ | ❌ | ❌ | ❌ |
| Update Organization | ✅ | ❌ | ❌ | ❌ |
| Change Org Status | ✅ | ❌ | ❌ | ❌ |
| List All Users | ✅ | ❌ | ❌ | ❌ |
| Manage Users | ✅ | ❌ | ❌ | ❌ |
| Grant PM Status | ✅ | ❌ | ❌ | ❌ |
| View Audit Log | ✅ | ❌ | ❌ | ❌ |

## Deployment Checklist

1. **Pre-Deployment**
   - [ ] All security tests pass
   - [ ] Code review completed
   - [ ] Security review completed
   - [ ] Documentation updated

2. **Deployment**
   - [ ] Run migrations
   - [ ] Seed initial platform manager(s)
   - [ ] Verify middleware is registered
   - [ ] Verify routes are accessible

3. **Post-Deployment**
   - [ ] Verify PM can access dashboard
   - [ ] Verify cross-tenant data access works
   - [ ] Verify audit logging works
   - [ ] Verify token revocation works
   - [ ] Test non-PM access is blocked

## Incident Response

### If Unauthorized Access Detected

1. Immediately revoke PM status of compromised account
2. Invalidate all tokens for that user
3. Review audit logs for malicious actions
4. Notify security team
5. Reset passwords for affected accounts

### If PM Locked Out

1. Use Artisan command to create new PM:
   ```bash
   php artisan opbx:create-platform-manager
   ```
2. Verify new PM can access platform
3. Investigate root cause
4. Update documentation

## Regular Maintenance

### Weekly

- [ ] Review audit logs for suspicious activity
- [ ] Verify PM count hasn't changed unexpectedly
- [ ] Check for failed login attempts

### Monthly

- [ ] Review PM list and remove inactive
- [ ] Verify audit log cleanup is working
- [ ] Update documentation

### Quarterly

- [ ] Security audit of platform management
- [ ] Penetration testing
- [ ] Review and update security policies

## Compliance

### GDPR

- [ ] Audit logs include lawful basis for processing
- [ ] Users can request their audit trail
- [ ] Audit logs are retained only as long as necessary

### SOC 2

- [ ] Access controls are documented
- [ ] All access is logged
- [ ] Regular access reviews are conducted

### HIPAA (if applicable)

- [ ] Audit logs are tamper-evident
- [ ] Access is role-based
- [ ] Regular security assessments

---

**Last Updated**: 2026-03-03
**Owner**: Security Team
**Review Cycle**: Quarterly
