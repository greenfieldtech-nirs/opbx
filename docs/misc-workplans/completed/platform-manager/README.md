# Platform Manager Feature Specification

## Document Control

| Field | Value |
|---|---|
| **Document ID** | OPBX-FEAT-PM-001 |
| **Feature Name** | Platform Manager |
| **Version** | 1.0.0 |
| **Status** | DRAFT |
| **Author** | Technical Architecture Team |
| **Created** | 2026-03-01 |
| **Last Updated** | 2026-03-01 |
| **Target Release** | TBD |
| **Review Status** | PENDING |

### Revision History

| Version | Date | Author | Changes |
|---|---|---|---|
| 1.0.0 | 2026-03-01 | Architecture Team | Initial specification |
| 1.0.1 | 2026-03-01 | Architecture Team | Split into multi-file structure |

---

## Table of Contents

This specification is organized into 12 files, each covering a distinct aspect of the Platform Manager feature. Files are numbered for reading order.

| # | File | Section | Description |
|---|------|---------|-------------|
| 1 | [01-executive-summary.md](01-executive-summary.md) | Executive Summary | Problem statement, solution overview, design principles |
| 2 | [02-requirements.md](02-requirements.md) | Requirements | Functional requirements (PM-F01–PM-F08) and non-functional requirements (PM-NF01–PM-NF03) |
| 3 | [03-architecture-design.md](03-architecture-design.md) | Architecture Design | Database changes, backend architecture (bypass mechanism, middleware, audit service, model changes, token abilities), API route design, frontend architecture |
| 4 | [04-implementation-plan.md](04-implementation-plan.md) | Implementation Plan | 8 phases with 70+ uniquely-IDed tasks `[PM-x.y.z]`, each with file paths, complexity estimates, and dependencies |
| 5 | [05-file-manifest.md](05-file-manifest.md) | File Manifest | Complete list of 42 new files and 9 modified files |
| 6 | [06-api-reference.md](06-api-reference.md) | API Reference | Full request/response examples for all 11 platform endpoints |
| 7 | [07-database-schema.md](07-database-schema.md) | Database Schema | `users` table modification + `platform_audit_logs` table with full migration code |
| 8 | [08-testing-plan.md](08-testing-plan.md) | Testing Plan | 59 tests (unit + feature + frontend), test data setup trait, detailed test specifications |
| 9 | [09-security-considerations.md](09-security-considerations.md) | Security Considerations | Auth layers, mass assignment, bypass safety, token abilities, audit trail, rate limiting, input validation, future recommendations |
| 10 | [10-acceptance-criteria.md](10-acceptance-criteria.md) | Acceptance Criteria | 57 testable checkboxes across 9 categories (org mgmt, user mgmt, PM flag, audit, CLI, frontend, security, compat, performance) |
| 11 | [11-risk-assessment.md](11-risk-assessment.md) | Risk Assessment | 12 risks with likelihood/impact scoring and mitigations |
| 12 | [12-developer-notes.md](12-developer-notes.md) | Developer Notes | Feedback template, implementation notes, common pitfalls, testing tips |

---

## Quick Start

**For implementers:** Start with [01-executive-summary.md](01-executive-summary.md) for context, then follow [04-implementation-plan.md](04-implementation-plan.md) phase by phase.

**For reviewers:** Read [02-requirements.md](02-requirements.md) and [10-acceptance-criteria.md](10-acceptance-criteria.md) to understand scope and success criteria.

**For security review:** Focus on [09-security-considerations.md](09-security-considerations.md) and [11-risk-assessment.md](11-risk-assessment.md).

## Key Design Decisions

1. **Boolean flag, not a role** — `is_platform_manager` is a boolean column on the `users` table, NOT a new `UserRole` enum value. Users retain their existing org role.
2. **Separate route group** — All platform endpoints live under `/api/v1/platform/` with dedicated middleware.
3. **Controlled scope bypass** — `OrganizationScope::bypass(callable)` uses a counter-based mechanism with `try/finally` safety.
4. **CLI-only bootstrap** — The first platform manager can only be created via artisan commands.
5. **Audit everything** — Every cross-tenant mutation is logged in `platform_audit_logs`.

## Progress Tracking

Implementation tasks use checkboxes (`- [ ]` / `- [x]`) throughout the spec files. Each task has a unique ID (e.g., `[PM-1.1.1]`). Developers can add inline feedback using:

```
> **DEV FEEDBACK (YYYY-MM-DD):** [Name]
> **Phase/Task ID:** [PM-X.Y.Z]
> **Type:** [Question | Blocker | Suggestion | Resolved]
> **Message:** [Your feedback here]
```

---

*Platform Manager Feature Specification — Document ID: OPBX-FEAT-PM-001 v1.0.0*
