# Voice Routing Cache System

**Phase 1 Step 8: Redis Caching Layer**

This document describes the voice routing cache system implemented to improve performance and reduce database load during call routing operations.

## Overview

The voice routing cache system implements a cache-aside pattern using Redis to cache frequently accessed data:
- Extension lookups (by organization and extension number)
- Active business hours schedules (with nested relationships)

Cache entries are automatically invalidated when underlying data changes, ensuring routing decisions always use current data.

## Architecture

### Components

1. **VoiceRoutingCacheService** (`app/Services/VoiceRouting/VoiceRoutingCacheService.php`)
   - Core service managing cache operations
   - Implements cache-aside pattern
   - Handles cache misses with database fallback
   - Provides invalidation methods

2. **Model Observers** (automatic cache invalidation)
   - `ExtensionCacheObserver` - invalidates extension cache on update/delete
   - `BusinessHoursScheduleCacheObserver` - invalidates schedule cache on update/delete
   - `BusinessHoursScheduleDayCacheObserver` - invalidates parent schedule cache
   - `BusinessHoursTimeRangeCacheObserver` - invalidates parent schedule cache
   - `BusinessHoursExceptionCacheObserver` - invalidates parent schedule cache

3. **Integration Points**
   - `VoiceRoutingController` - uses cache service for routing decisions
   - Registered in `AppServiceProvider` as singleton
   - Observers registered in `AppServiceProvider::boot()`

## Cache Keys

### Key Format

All cache keys use a consistent naming pattern:

```
routing:<resource_type>:<organization_id>[:<identifier>]
```

### Specific Keys

**Extension Cache:**
```
routing:extension:{organization_id}:{extension_number}

Example: routing:extension:42:1001
```

**Business Hours Schedule Cache:**
```
routing:business_hours:{organization_id}

Example: routing:business_hours:42
```

### Why This Format?

- **Namespace**: `routing:` prefix isolates voice routing cache from other cache entries
- **Resource Type**: Clear identification of cached data type
- **Organization ID**: Ensures multi-tenant isolation
- **Identifier**: Unique identifier within organization (extension number, etc.)

## Cache TTLs

| Resource Type | TTL | Rationale |
|--------------|-----|-----------|
| Extensions | 30 minutes (1800s) | Extensions change infrequently, auto-invalidated on update |
| Business Hours Schedules | 15 minutes (900s) | Schedules may have time-based rules, shorter TTL for safety |

TTL values are defined as constants in `VoiceRoutingCacheService`:
- `EXTENSION_CACHE_TTL = 1800`
- `BUSINESS_HOURS_CACHE_TTL = 900`

## Cache Invalidation

### Automatic Invalidation

Model observers automatically invalidate cache when data changes:

**Direct Model Changes:**
- Extension updated → invalidates `routing:extension:{org_id}:{ext_number}`
- Extension deleted → invalidates `routing:extension:{org_id}:{ext_number}`
- Schedule updated → invalidates `routing:business_hours:{org_id}`
- Schedule deleted → invalidates `routing:business_hours:{org_id}`

**Nested Model Changes:**
- Schedule day created/updated/deleted → invalidates parent schedule cache
- Time range created/updated/deleted → invalidates parent schedule cache
- Exception created/updated/deleted → invalidates parent schedule cache

### Manual Invalidation

Use the cache service methods for manual invalidation:

```php
use App\Services\VoiceRouting\VoiceRoutingCacheService;

$cache = app(VoiceRoutingCacheService::class);

// Invalidate extension cache
$cache->invalidateExtension($organizationId, $extensionNumber);

// Invalidate business hours schedule cache
$cache->invalidateBusinessHoursSchedule($organizationId);

// Clear all routing cache for an organization
$cache->clearOrganizationCache($organizationId);
```

## Usage Examples

### Fetching Cached Extension

```php
use App\Services\VoiceRouting\VoiceRoutingCacheService;

$cache = app(VoiceRoutingCacheService::class);

// First call: Cache miss, loads from database, stores in cache
$extension = $cache->getExtension($organizationId, '1001');

// Second call: Cache hit, returns from Redis (no database query)
$extension = $cache->getExtension($organizationId, '1001');

// After extension update, cache is automatically invalidated by observer
$extension->status = 'inactive';
$extension->save();

// Next call: Cache miss again, loads fresh data from database
$extension = $cache->getExtension($organizationId, '1001');
```

### Fetching Cached Business Hours Schedule

```php
use App\Services\VoiceRouting\VoiceRoutingCacheService;

$cache = app(VoiceRoutingCacheService::class);

// Returns active schedule with all nested relationships loaded
$schedule = $cache->getActiveBusinessHoursSchedule($organizationId);

if ($schedule) {
    // Access nested data (all cached together)
    foreach ($schedule->scheduleDays as $day) {
        foreach ($day->timeRanges as $range) {
            // Time ranges are cached with the schedule
        }
    }

    foreach ($schedule->exceptions as $exception) {
        // Exceptions are cached with the schedule
    }
}
```

## Performance Characteristics

### Expected Improvements

Based on integration tests:

- **Cache hits**: 0 database queries (100% cache efficiency)
- **Extension lookups**: Typically 50-90% faster with cache
- **Business hours lookups**: Significant improvement due to complex relationships
- **Multi-tenant scale**: Linear scaling with organization count

### Cache Hit Rate

Expected cache hit rates in production:

- **Extension lookups during active calls**: >95%
- **Business hours checks**: >90% (varies by call volume patterns)

### Performance Testing

Run integration tests to verify performance:

```bash
php artisan test tests/Integration/VoiceRoutingCacheIntegrationTest.php
```

Key test: `test_cache_provides_performance_improvement` - logs performance metrics

## Monitoring & Debugging

### Cache Statistics (Manual Check)

```php
use Illuminate\Support\Facades\Cache;

// Check if extension is cached
$cached = Cache::has("routing:extension:{$orgId}:1001");

// Get cached value (returns null if not cached)
$value = Cache::get("routing:extension:{$orgId}:1001");

// Get cache with TTL information
$ttl = Cache::get("routing:extension:{$orgId}:1001");
```

### Log Analysis

Cache operations are logged at DEBUG level:

```
Voice routing cache: Extension cache miss, loading from database
Voice routing cache: Extension retrieved (from_cache: true)
Voice routing cache: Extension cache invalidated
```

To see cache logs in development:

```bash
# Set LOG_LEVEL=debug in .env
LOG_LEVEL=debug

# Tail logs
tail -f storage/logs/laravel.log | grep "Voice routing cache"
```

### Cache Flush (Development/Testing)

```bash
# Clear all cache
php artisan cache:clear

# Or in code
Cache::flush();
```

## Failure Modes & Resilience

### Redis Unavailable

The cache service includes try-catch blocks that fallback to database queries if Redis is unavailable:

```php
try {
    // Try cache
    $extension = Cache::remember(/* ... */);
} catch (\Exception $e) {
    // Fallback to direct database query
    Log::warning('Voice routing cache: Cache unavailable, falling back to database');
    return Extension::where(/* ... */)->first();
}
```

**Impact**: System remains operational, but performance degrades to pre-cache levels.

### Stale Cache (Edge Cases)

**Scenario**: Observer doesn't fire (rare test environment issue).

**Mitigation**:
- TTL ensures stale data expires within 30 minutes (extensions) or 15 minutes (schedules)
- Manual invalidation available via cache service methods

### Cache Key Collisions

**Prevention**: Organization ID in every cache key ensures tenant isolation.

**Verification**: Integration tests confirm cache isolation between organizations.

## Configuration

### Redis Configuration

Cache configuration in `config/cache.php` uses the `redis` driver.

Default Redis connection from `config/database.php`:

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_CACHE_DB', 1),
    ],
],
```

### Environment Variables

```bash
# Redis connection
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_CACHE_DB=1

# Cache driver (should be redis)
CACHE_DRIVER=redis
```

### Docker Configuration

Redis service defined in `docker-compose.yml`:

```yaml
redis:
  image: redis:alpine
  ports:
    - "6379:6379"
```

## Testing

### Unit Tests

**Extension Cache Observer** (`tests/Unit/Observers/ExtensionCacheObserverTest.php`):
- 10 tests, 32 assertions
- Tests cache invalidation on update/delete
- Tests organization isolation

**Business Hours Cache Observer** (`tests/Unit/Observers/BusinessHoursScheduleCacheObserverTest.php`):
- 12 tests, 34 assertions
- Tests cache invalidation on schedule changes
- Tests nested model invalidation

**Cache Service** (`tests/Unit/Services/VoiceRoutingCacheServiceTest.php`):
- 15 tests, 38 assertions
- Tests cache hit/miss scenarios
- Tests TTL behavior
- Tests multi-tenant isolation

### Integration Tests

**Voice Routing Cache Integration** (`tests/Integration/VoiceRoutingCacheIntegrationTest.php`):
- 10 tests, 57 assertions
- Tests complete workflows
- Tests performance improvements
- Tests multi-organization scaling

### Running Tests

```bash
# All cache tests
php artisan test --filter=Cache

# Specific test suites
php artisan test tests/Unit/Services/VoiceRoutingCacheServiceTest.php
php artisan test tests/Unit/Observers/
php artisan test tests/Integration/VoiceRoutingCacheIntegrationTest.php
```

## Maintenance

### Adding New Cached Resources

To add caching for a new resource type:

1. **Add methods to VoiceRoutingCacheService:**
   ```php
   public function getResourceName(int $organizationId, string $identifier): ?Model
   {
       $cacheKey = "routing:resource_name:{$organizationId}:{$identifier}";
       return Cache::remember($cacheKey, self::RESOURCE_TTL, function() {
           return Model::where(/* ... */)->first();
       });
   }

   public function invalidateResourceName(int $organizationId, string $identifier): void
   {
       Cache::forget("routing:resource_name:{$organizationId}:{$identifier}");
   }
   ```

2. **Create observer:**
   ```php
   class ResourceNameCacheObserver
   {
       public function __construct(private readonly VoiceRoutingCacheService $cache) {}

       public function updated(Model $model): void
       {
           $this->cache->invalidateResourceName($model->organization_id, $model->identifier);
       }
   }
   ```

3. **Register observer in AppServiceProvider:**
   ```php
   \App\Models\ResourceName::observe(\App\Observers\ResourceNameCacheObserver::class);
   ```

4. **Add tests** for the new cached resource.

### Adjusting TTLs

TTL values can be adjusted in `VoiceRoutingCacheService`:

```php
// Increase TTL for better performance (if data changes infrequently)
private const EXTENSION_CACHE_TTL = 3600; // 1 hour

// Decrease TTL for faster consistency (if data changes frequently)
private const BUSINESS_HOURS_CACHE_TTL = 300; // 5 minutes
```

**Considerations:**
- Longer TTL = better performance, but slower to reflect changes
- Shorter TTL = more database queries, but faster consistency
- Observers handle most invalidation, so longer TTLs are generally safe

## Troubleshooting

### Cache Not Invalidating

**Symptoms**: Updates don't reflect in routing decisions.

**Checks**:
1. Verify observers are registered in `AppServiceProvider::boot()`
2. Check logs for observer execution
3. Verify cache driver is `redis` in `.env`
4. Manually invalidate to test: `$cache->clearOrganizationCache($orgId)`

### Poor Cache Hit Rate

**Symptoms**: High database load despite caching.

**Checks**:
1. Verify Redis is running: `docker compose ps redis`
2. Check Redis connection: `php artisan cache:clear` (should not error)
3. Review logs for "Cache unavailable" warnings
4. Check TTL values aren't too short

### Memory Issues (Redis)

**Symptoms**: Redis using excessive memory.

**Solutions**:
1. Verify TTLs are set (prevents infinite growth)
2. Monitor cache key count: `redis-cli DBSIZE`
3. Increase Redis max memory if needed
4. Consider cache eviction policies in Redis config

## Future Enhancements

Potential improvements for future phases:

1. **Cache warming**: Pre-populate cache for active organizations on startup
2. **Cache tags**: Group related cache entries for bulk invalidation
3. **Metrics**: Track cache hit/miss rates in production
4. **Distributed caching**: Redis Sentinel or Cluster for high availability
5. **Query result caching**: Cache entire routing decision results
6. **TTL optimization**: Dynamic TTLs based on change frequency

## References

- Service: `app/Services/VoiceRouting/VoiceRoutingCacheService.php`
- Observers: `app/Observers/*CacheObserver.php`
- Tests: `tests/Unit/Services/`, `tests/Unit/Observers/`, `tests/Integration/`
- Configuration: `config/cache.php`, `config/database.php`
- Laravel Cache Documentation: https://laravel.com/docs/cache
