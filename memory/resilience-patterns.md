# Resilience Patterns

## Overview
Fault tolerance mechanisms for external service calls and distributed state. Three components: circuit breaker, resilient cache, and database lock fallback.

## Source Files
| File | Purpose |
|------|---------|
| `app/Services/CircuitBreaker/CircuitBreaker.php` | Classic circuit breaker pattern |
| `app/Services/CircuitBreaker/CircuitBreakerOpenException.php` | Exception class |
| `app/Services/Fallback/ResilientCacheService.php` | Cache with DB fallback |
| `app/Services/Fallback/DatabaseLockService.php` | DB-based distributed locking |
| `config/circuit-breaker.php` | Circuit breaker configuration |

## Circuit Breaker (CircuitBreaker.php)

### State Machine
```
CLOSED -> (failures >= threshold) -> OPEN -> (after retryAfterSeconds) -> HALF_OPEN
  ^                                                                         |
  +--- success -----------------------------------------------------------+
  HALF_OPEN -> (failure) -> OPEN
```

### Constructor Parameters
| Param | Default | Purpose |
|-------|---------|---------|
| `serviceName` | required | Cache key prefix |
| `failureThreshold` | 5 | Failures to trigger OPEN |
| `timeoutSeconds` | 30 | HTTP timeout |
| `retryAfterSeconds` | 60 | Wait before HALF_OPEN test |

### Usage
```php
$breaker->call(
    fn() => $httpClient->get('/endpoint'),  // primary
    fn() => $cachedResult                    // fallback (optional)
);
```

### Cache Keys
`circuit_breaker:{serviceName}:state`, `circuit_breaker:{serviceName}:failures`, `circuit_breaker:{serviceName}:opened_at`, `circuit_breaker:{serviceName}:last_failure_at`

### Where Used
- `CloudonixClient` wraps all API calls with circuit breaker
- `withCircuitBreaker()` method provides cached fallback values

## ResilientCacheService (Fallback/ResilientCacheService.php)

Gracefully degrades from Redis to database or no-cache:

| Method | Redis OK | Redis Down |
|--------|----------|------------|
| `lock()` | Redis distributed lock | `DatabaseLockService` fallback |
| `remember()` | `Cache::remember()` | Direct callback (no caching) |
| `get()` | Cache get | Returns default value |
| `put()` | Cache put | Returns false silently |
| `forget()` | Cache delete | Returns false silently |

### Health Checking
- In-memory `$redisAvailable` flag with 60s periodic re-check
- `checkRedisHealth()`: writes/reads/deletes test key
- Logs error only once per unavailability transition

## DatabaseLockService (Fallback/DatabaseLockService.php)

Database-based distributed locking as Redis fallback.

### Table: `locks`
Columns: key, owner (UUID), expires_at, created_at, updated_at

### Key Methods
| Method | Purpose |
|--------|---------|
| `acquire(key, ttl)` | Insert lock row, clean expired, returns owner UUID |
| `release(key, owner)` | Delete by key+owner (safe: only acquirer can release) |
| `block(key, callback, ttl, timeout)` | Spin-wait (100ms intervals) until lock acquired |
| `cleanupAll()` | Delete all expired locks (for scheduled task) |

## Related Modules
- [Settings & Cloudonix](settings-cloudonix.md) - Circuit breaker protects Cloudonix API
- [Voice Routing](voice-routing-engine.md) - Cache service used for routing cache
