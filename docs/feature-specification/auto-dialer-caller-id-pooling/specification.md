# Auto Dialer Caller ID Pooling Feature Specification

## Document Information
- **Feature Name**: Auto Dialer Caller ID Pooling
- **Status**: Draft
- **Author**: John The Great (Engineering Lead)
- **Date**: 2026-04-10
- **Version**: 1.0

---

## 1. Executive Summary

### 1.1 Current State
The Auto Dialer feature currently supports a single Caller ID assignment per campaign. This limits organizations that want to distribute outbound calls across multiple phone numbers for load balancing, carrier redundancy, or compliance reasons.

### 1.2 Proposed Change
Enable campaigns to have multiple Caller IDs (up to 100) selected from the organization's active DIDs. The system will automatically cycle through these Caller IDs using configurable strategies (Round Robin, Random, Least Recently Used).

### 1.3 Business Value
- **Carrier Load Distribution**: Spread calls across multiple carrier trunks
- **Compliance**: Rotate numbers to comply with local regulations
- **Redundancy**: Automatic failover if a specific Caller ID becomes unavailable
- **Analytics**: Track performance per Caller ID

---

## 2. Functional Requirements

### 2.1 Caller ID Selection

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-001 | Caller IDs MUST be selected from the organization's active DIDs (`did_numbers` table) | Must |
| FR-002 | Only DIDs with `status = 'active'` can be selected | Must |
| FR-003 | Maximum of 100 Caller IDs per campaign | Must |
| FR-004 | The same DID can be added multiple times for weighted distribution | Should |
| FR-005 | Minimum of 1 Caller ID required (backward compatible) | Must |

### 2.2 Distribution Strategies

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-006 | Support **Round Robin** strategy: sequential cycling through the pool | Must |
| FR-007 | Support **Random** strategy: uniform random selection | Must |
| FR-008 | Support **Least Recently Used (LRU)** strategy: select the least recently used Caller ID | Must |
| FR-009 | Strategy selection MUST be configurable per-campaign | Must |
| FR-010 | Default strategy MUST be Round Robin | Should |

### 2.3 State Management

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-011 | Cycle state MUST be stored in Redis (ephemeral) | Must |
| FR-012 | State MUST be per-campaign (isolated) | Must |
| FR-013 | Cycle position MUST persist across pause/resume operations | Must |
| FR-014 | If a Caller ID becomes inactive mid-campaign, it MUST be skipped | Must |

### 2.4 Go Worker Integration

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-015 | Worker MUST receive the full Caller ID pool via API | Must |
| FR-016 | Worker MUST implement strategy logic locally | Must |
| FR-017 | Worker MUST track LRU state per-campaign | Must |
| FR-018 | Caller ID selection MUST happen at call initiation time | Must |
| FR-024 | On retry (when retry counter > 1), worker MUST select a DIFFERENT Caller ID from the pool | Must |
| FR-025 | Worker MUST track tried Caller IDs per destination session to avoid reuse on retries | Must |
| FR-026 | When all Caller IDs have been tried for a destination, worker MUST reset and cycle through pool again | Must |

### 2.5 Observability

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-019 | Each call MUST record which Caller ID was used | Must |
| FR-020 | Usage statistics MUST be tracked per Caller ID per campaign | Should |
| FR-021 | Call logs and monitor MUST display the Caller ID used | Must |

### 2.6 Backward Compatibility

| ID | Requirement | Priority |
|----|-------------|----------|
| FR-022 | Existing campaigns MUST be auto-migrated to the new pool format | Must |
| FR-023 | Single Caller ID campaigns MUST continue to work unchanged | Must |

---

## 3. Non-Functional Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| NFR-001 | Caller ID selection latency MUST be < 10ms | Must |
| NFR-002 | Redis state MUST have TTL of 24 hours minimum | Must |
| NFR-003 | Migration MUST be reversible in case of rollback | Should |
| NFR-004 | No downtime for existing campaigns during deployment | Must |

---

## 4. Out of Scope

The following features are explicitly out of scope for this version:

1. Per-Caller ID rate limiting (covered by per-campaign CAC/CPS)
2. Time-based Caller ID selection
3. Geographic/region-based Caller ID selection
4. Automatic Caller ID health checking
5. Webhook notifications for Caller ID failures

---

## 5. Related Documents

- [Database Schema](database-schema.md)
- [API Specification](api-specification.md)
- [Frontend Specification](frontend-specification.md)
- [Go Worker Specification](worker-specification.md)
- [Implementation Plan](implementation-plan.md)

---

## 6. Glossary

| Term | Definition |
|------|------------|
| **Caller ID Pool** | The collection of phone numbers assigned to a campaign for outbound calling |
| **Round Robin** | Sequential cycling strategy: 1, 2, 3, 1, 2, 3... |
| **LRU** | Least Recently Used - selects the Caller ID used longest ago |
| **CAC** | Concurrent Active Calls - existing rate limiter |
| **CPS** | Calls Per Second - existing rate limiter |

---

## 7. Notes

### 7.1 Caller ID Validation (Q4.2 Clarification)
Cloudonix does NOT reject call initiation based on Caller ID input. Call failures occur due to network/congestion reasons only. On retry (when `max_dial_attempts > 1`), the worker MUST select a different Caller ID from the pool than previously tried for that destination.

---

## 8. Approval

| Role | Name | Date | Status |
|------|------|------|--------|
| Product Owner | TBD | | Pending |
| Tech Lead | John The Great | 2026-04-10 | Approved |
| QA Lead | TBD | | Pending |
