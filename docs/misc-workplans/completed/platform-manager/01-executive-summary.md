## 1. Executive Summary

### Purpose

The Platform Manager feature introduces a cross-organizational super-admin capability to the OPBX system. Unlike the existing role-based access control (owner, pbx_admin, pbx_user, reporter), the Platform Manager is implemented as a **boolean flag** on the users table (`is_platform_manager`), granting designated users the ability to view and manage all organizations, users, and configurations across the entire platform.

### Problem Statement

Currently, OPBX enforces strict tenant isolation via the `OrganizationScope` global scope. While this is correct for normal operations, there is no mechanism for platform-level administration — no way to view all organizations, manage suspended tenants, or perform cross-tenant troubleshooting without direct database access.

### Solution Overview

The solution adds a lightweight super-admin layer **on top of** the existing role system:

- A `is_platform_manager` boolean column on the `users` table (default `false`)
- A dedicated middleware (`platform.manager`) that validates the flag
- A mechanism to temporarily bypass `OrganizationScope` for platform management queries
- A separate API route group (`/api/v1/platform/`) with platform-scoped endpoints
- A separate frontend route group (`/ui/platform/`) with dedicated pages
- A `platform_audit_logs` table recording every cross-tenant action
- Artisan commands for bootstrapping the first platform manager

### Design Principles

1. **Additive, not disruptive** — Zero changes to existing role logic, policies, or middleware for non-platform-manager users
2. **Explicit separation** — Platform management routes, middleware, and frontend are entirely separate from tenant-scoped code
3. **Audit-first** — Every cross-tenant action is logged before it is executed
4. **Defense in depth** — Multiple layers of validation: middleware, policy, scope bypass controls
5. **Bootstrap-safe** — The first platform manager can only be created via CLI, never via API or UI self-service

---

