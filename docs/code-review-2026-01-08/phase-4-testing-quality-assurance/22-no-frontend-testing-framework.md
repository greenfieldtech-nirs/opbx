# Issue #22: No Frontend Testing Framework

**Status:** Pending
**Priority:** Important
**Estimated Effort:** 2-3 hours
**Assigned:** Unassigned

## Problem Description

No testing framework configured for React components, preventing verification of frontend functionality.

**Location:** `frontend/` directory

## Impact Assessment

- **Severity:** Important - Testing gap
- **Scope:** Frontend reliability
- **Risk:** High - Cannot verify frontend works
- **Dependencies:** Testing tools, CI/CD

## Solution Overview

Set up Jest + React Testing Library with TypeScript support and integrate into development workflow.

## Implementation Steps

### Step 1: Install Testing Dependencies (30 minutes)
1. Install Jest, React Testing Library, Vitest
2. Add TypeScript support
3. Configure testing environment

### Step 2: Configure Testing Framework (1 hour)
1. Set up Jest configuration
2. Configure path aliases
3. Add test scripts to package.json
4. Set up test environment

### Step 3: Create Test Examples (30 minutes)
1. Write basic component tests
2. Add hook testing examples
3. Create utility test helpers

### Step 4: CI/CD Integration (30 minutes)
1. Add testing to build pipeline
2. Configure coverage reporting
3. Set up test reporting

## Code Changes

### File: `frontend/package.json` (Add dependencies)
```json
{
  "devDependencies": {
    "@testing-library/jest-dom": "^6.1.4",
    "@testing-library/react": "^14.1.2",
    "@testing-library/user-event": "^14.5.1",
    "@types/jest": "^29.5.5",
    "jsdom": "^22.1.0",
    "vitest": "^0.34.6"
  },
  "scripts": {
    "test": "vitest",
    "test:ui": "vitest --ui",
    "test:coverage": "vitest --coverage"
  }
}
```

### New File: `frontend/vitest.config.ts`
```typescript
import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import { resolve } from 'path'

export default defineConfig({
  plugins: [react()],
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, './src'),
    },
  },
})
```

### New File: `frontend/src/test/setup.ts`
```typescript
import '@testing-library/jest-dom'
import { expect, afterEach } from 'vitest'
import { cleanup } from '@testing-library/react'
import * as matchers from '@testing-library/jest-dom/matchers'

expect.extend(matchers)

afterEach(() => {
  cleanup()
})
```

### Example Test File: `frontend/src/components/Button.test.tsx`
```typescript
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Button } from './Button'

describe('Button', () => {
  it('renders with correct text', () => {
    render(<Button>Click me</Button>)
    expect(screen.getByRole('button', { name: /click me/i })).toBeInTheDocument()
  })

  it('calls onClick when clicked', async () => {
    const user = userEvent.setup()
    const handleClick = vi.fn()
    
    render(<Button onClick={handleClick}>Click me</Button>)
    
    await user.click(screen.getByRole('button', { name: /click me/i }))
    
    expect(handleClick).toHaveBeenCalledTimes(1)
  })
})
```

## Verification Steps

1. **Test Runner:**
   ```bash
   cd frontend
   npm test
   ```

2. **Coverage Report:**
   ```bash
   npm run test:coverage
   ```

3. **CI/CD Integration:**
   - Verify tests run in pipeline
   - Check coverage reports generated

## Rollback Plan

If testing setup causes issues:
1. Keep tests optional initially
2. Start with simple unit tests
3. Gradually add complexity

## Testing Requirements

- [ ] Jest/Vitest configured
- [ ] React Testing Library working
- [ ] Basic component tests pass
- [ ] CI/CD integration active

## Documentation Updates

- Update development setup guide
- Document testing patterns
- Mark as completed in master work plan

## Completion Criteria

- [ ] Testing framework configured
- [ ] Basic tests written
- [ ] CI/CD integration working
- [ ] Code reviewed and approved

---

**Estimated Completion:** 2-3 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #23: Missing Critical Integration Tests

**Status:** Pending
**Priority:** Important
**Estimated Effort:** 6-8 hours
**Assigned:** Unassigned

## Problem Description

No end-to-end call routing tests, missing webhook retry logic tests. Cannot verify complete call flows work.

**Location:** `tests/` directory

## Impact Assessment

- **Severity:** Important - Integration testing gap
- **Scope:** Complete system functionality
- **Risk:** High - Core features untested
- **Dependencies:** Test database, Cloudonix integration

## Solution Overview

Add comprehensive integration tests for call routing scenarios, webhook handling, and end-to-end flows.

## Implementation Steps

### Phase 1: Test Infrastructure Setup (1 hour)
1. Set up test database seeding
2. Configure Cloudonix test environment
3. Create test helpers and factories

### Phase 2: Call Routing Integration Tests (3-4 hours)
1. Test DID resolution
2. Test business hours routing
3. Test extension routing
4. Test IVR menu navigation

### Phase 3: Webhook Processing Tests (2 hours)
1. Test webhook idempotency
2. Test out-of-order event handling
3. Test retry logic
4. Test concurrent webhook processing

### Phase 4: End-to-End Scenario Tests (1-2 hours)
1. Complete call flow tests
2. Multi-step IVR tests
3. Error scenario tests
4. Performance tests

## Code Changes

### New File: `tests/Feature/CallRoutingIntegrationTest.php`
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CallRoutingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_call_routes_to_extension()
    {
        // Create test data
        $organization = Organization::factory()->create();
        $did = DidNumber::factory()->create([
            'organization_id' => $organization->id,
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => 1]
        ]);
        
        $extension = Extension::factory()->create([
            'organization_id' => $organization->id,
            'sip_uri' => 'sip:test@example.com'
        ]);

        // Simulate webhook
        $webhookData = [
            'did' => $did->number,
            'call_sid' => 'test-call-123',
            'direction' => 'inbound'
        ];

        $response = $this->postJson('/api/voice/route', $webhookData);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'Connect' => [
                        'To' => ['@sipUri']
                    ]
                ]);
    }

    public function test_business_hours_routing()
    {
        // Test business hours vs after hours routing
        $this->markTestIncomplete('Business hours routing test');
    }
}
```

### New File: `tests/Feature/WebhookIntegrationTest.php`
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\CallState;
use Illuminate\Support\Facades\Redis;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WebhookIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_idempotency()
    {
        $webhookData = [
            'event' => 'call.initiated',
            'call_sid' => 'test-call-123',
            'did' => '15551234567'
        ];

        // First request
        $response1 = $this->postJson('/api/voice/route', $webhookData);
        $response1->assertStatus(200);

        // Duplicate request should be idempotent
        $response2 = $this->postJson('/api/voice/route', $webhookData);
        $response2->assertStatus(200);

        // Should not create duplicate call state
        $callStates = CallState::where('call_sid', 'test-call-123')->count();
        $this->assertEquals(1, $callStates);
    }

    public function test_concurrent_webhook_processing()
    {
        // Test race condition handling
        $this->markTestIncomplete('Concurrent processing test');
    }
}
```

### New File: `tests/Feature/EndToEndCallFlowTest.php`
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\DidNumber;
use App\Models\IvrMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EndToEndCallFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_ivr_flow()
    {
        // Create IVR menu with options
        $ivrMenu = IvrMenu::factory()->create();
        
        // Simulate complete call flow through IVR
        $this->markTestIncomplete('End-to-end IVR test');
    }
}
```

## Verification Steps

1. **Integration Test Suite:**
   ```bash
   php artisan test --testsuite=integration
   ```

2. **Database State Verification:**
   - Check call logs created
   - Verify call states updated
   - Confirm audit trails

3. **External Integration:**
   - Test Cloudonix webhook format compatibility
   - Verify CXML response generation

## Rollback Plan

If integration tests are complex:
1. Start with unit tests for individual components
2. Gradually build up to integration tests
3. Use feature flags to enable/disable complex tests

## Testing Requirements

- [ ] Call routing integration tests pass
- [ ] Webhook processing tests work
- [ ] End-to-end scenarios covered
- [ ] Race conditions handled

## Documentation Updates

- Document integration test scenarios
- Update testing guide
- Mark as completed in master work plan

## Completion Criteria

- [ ] Integration test suite created
- [ ] Critical paths tested
- [ ] Webhook handling verified
- [ ] Code reviewed and approved

---

**Estimated Completion:** 6-8 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #24: Failing Cache Tests

**Status:** Pending
**Priority:** Important
**Estimated Effort:** 4-6 hours
**Assigned:** Unassigned

## Problem Description

28+ failing cache invalidation tests indicate unreliable caching system.

**Location:** `tests/Feature/VoiceRoutingCacheIntegrationTest.php`

## Impact Assessment

- **Severity:** Important - Performance impact
- **Scope:** Caching system reliability
- **Risk:** Medium - Performance degradation
- **Dependencies:** Redis, cache observers

## Solution Overview

Fix cache observer implementations and ensure proper cache invalidation strategies.

## Implementation Steps

### Phase 1: Analyze Failing Tests (1 hour)
1. Run failing cache tests
2. Identify cache invalidation issues
3. Review cache observer logic

### Phase 2: Fix Cache Observers (2 hours)
1. Update model observers
2. Fix cache key generation
3. Implement proper invalidation logic

### Phase 3: Cache Strategy Refinement (1 hour)
1. Review cache TTL settings
2. Optimize cache key patterns
3. Add cache warming strategies

### Phase 4: Performance Testing (1 hour)
1. Test cache hit rates
2. Verify invalidation works
3. Monitor Redis performance

## Code Changes

### File: `app/Observers/DidNumberObserver.php`
```php
<?php

namespace App\Observers;

use App\Models\DidNumber;
use Illuminate\Support\Facades\Cache;

class DidNumberObserver
{
    public function updated(DidNumber $didNumber)
    {
        // Invalidate related caches
        Cache::forget("did:{$didNumber->id}");
        Cache::forget("did:{$didNumber->number}");
        
        // Invalidate organization caches
        Cache::forget("org:{$didNumber->organization_id}:dids");
    }

    public function deleted(DidNumber $didNumber)
    {
        // Clean up all related caches
        Cache::forget("did:{$didNumber->id}");
        Cache::forget("did:{$didNumber->number}");
        Cache::forget("org:{$didNumber->organization_id}:dids");
    }
}
```

### File: `app/Services/Cache/VoiceRoutingCache.php`
```php
<?php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class VoiceRoutingCache
{
    public function getDidRouting(string $didNumber): ?array
    {
        $cacheKey = "did:routing:{$didNumber}";
        
        return Cache::get($cacheKey);
    }

    public function setDidRouting(string $didNumber, array $routing, int $ttl = 3600): void
    {
        $cacheKey = "did:routing:{$didNumber}";
        
        Cache::put($cacheKey, $routing, $ttl);
    }

    public function invalidateDidRouting(string $didNumber): void
    {
        $cacheKey = "did:routing:{$didNumber}";
        
        Cache::forget($cacheKey);
        
        // Also invalidate with Redis directly for immediate effect
        Redis::del($cacheKey);
    }
}
```

### File: `tests/Feature/VoiceRoutingCacheIntegrationTest.php` (Update)
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\DidNumber;
use App\Services\Cache\VoiceRoutingCache;
use Illuminate\Foundation\Testing\RefreshDatabase;

class VoiceRoutingCacheIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cache_invalidation_on_did_update()
    {
        $cache = app(VoiceRoutingCache::class);
        $did = DidNumber::factory()->create();
        
        // Cache some routing data
        $routingData = ['type' => 'extension', 'target' => 'sip:test'];
        $cache->setDidRouting($did->number, $routingData);
        
        // Verify cached
        $this->assertEquals($routingData, $cache->getDidRouting($did->number));
        
        // Update DID
        $did->update(['routing_type' => 'ring_group']);
        
        // Verify cache invalidated
        $this->assertNull($cache->getDidRouting($did->number));
    }
}
```

## Verification Steps

1. **Cache Tests:**
   ```bash
   php artisan test --filter=VoiceRoutingCacheIntegrationTest
   ```

2. **Redis Monitoring:**
   ```bash
   # Check Redis keys
   redis-cli keys "did:*"
   ```

3. **Performance Benchmark:**
   - Measure cache hit rates
   - Test response times with/without cache

## Rollback Plan

If cache fixes cause issues:
1. Disable complex caching temporarily
2. Use simple in-memory cache
3. Gradually re-enable features

## Testing Requirements

- [ ] All cache tests pass
- [ ] Cache invalidation works
- [ ] Performance improved
- [ ] Redis usage optimized

## Documentation Updates

- Document caching strategies
- Update performance guide
- Mark as completed in master work plan

## Completion Criteria

- [ ] Cache tests passing
- [ ] Invalidation working correctly
- [ ] Performance benchmarks met
- [ ] Code reviewed and approved

---

**Estimated Completion:** 4-6 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #25: Deprecated PHPUnit Annotations

**Status:** Pending
**Priority:** Normal
**Estimated Effort:** 2-3 hours
**Assigned:** Unassigned

## Problem Description

Tests use old doc-comment metadata instead of PHPUnit attributes.

**Location:** Various test files

## Impact Assessment

- **Severity:** Normal - Maintenance issue
- **Scope:** Test suite modernization
- **Risk:** Low - Compatibility issues
- **Dependencies:** PHPUnit version

## Solution Overview

Update tests to use modern PHPUnit attribute syntax.

## Implementation Steps

### Phase 1: Identify Deprecated Usage (30 minutes)
1. Find all doc-comment annotations
2. Categorize by type (data providers, etc.)
3. Plan migration strategy

### Phase 2: Update Test Annotations (1-2 hours)
1. Convert to attribute syntax
2. Update data providers
3. Fix test dependencies

### Phase 3: PHPUnit Configuration (30 minutes)
1. Update phpunit.xml if needed
2. Verify compatibility
3. Test execution

### Phase 4: Documentation Update (30 minutes)
1. Update testing documentation
2. Document attribute usage

## Code Changes

### Before (Deprecated):
```php
/**
 * @dataProvider userDataProvider
 * @testWith ["admin", "password"]
 */
public function test_user_login($role, $password)
{
    // Test code
}

public function userDataProvider()
{
    return [
        ['admin', 'password'],
        ['user', 'password'],
    ];
}
```

### After (Modern):
```php
#[DataProvider('userDataProvider')]
public function test_user_login($role, $password): void
{
    // Test code
}

public static function userDataProvider(): array
{
    return [
        ['admin', 'password'],
        ['user', 'password'],
    ];
}
```

### File: `phpunit.xml` (Update if needed)
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="./vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">./tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory suffix="Test.php">./tests/Feature</directory>
        </testsuite>
    </testsuites>
    <coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">./app</directory>
        </include>
    </coverage>
</phpunit>
```

## Verification Steps

1. **Test Execution:**
   ```bash
   php artisan test
   ```

2. **PHPUnit Version Check:**
   ```bash
   ./vendor/bin/phpunit --version
   ```

3. **Deprecation Warnings:**
   - Ensure no deprecation warnings
   - Verify attribute syntax works

## Rollback Plan

If attribute syntax causes issues:
1. Keep mixed syntax temporarily
2. Gradually migrate tests
3. Use compatibility layer if needed

## Testing Requirements

- [ ] All tests pass with new syntax
- [ ] No deprecation warnings
- [ ] PHPUnit compatibility maintained
- [ ] Documentation updated

## Documentation Updates

- Update testing guide
- Document attribute syntax usage
- Mark as completed in master work plan

## Completion Criteria

- [ ] Tests use modern syntax
- [ ] No deprecation warnings
- [ ] PHPUnit configuration updated
- [ ] Code reviewed and approved

---

**Estimated Completion:** 2-3 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________

# Issue #26: No Coverage Metrics

**Status:** Pending
**Priority:** Normal
**Estimated Effort:** 1-2 hours
**Assigned:** Unassigned

## Problem Description

No test coverage reporting configured, cannot measure testing effectiveness.

**Location:** `phpunit.xml`

## Impact Assessment

- **Severity:** Normal - Visibility issue
- **Scope:** Test quality measurement
- **Risk:** Low - Affects development insights
- **Dependencies:** PHPUnit, CI/CD

## Solution Overview

Configure PHPUnit coverage reporting with HTML and Clover formats.

## Implementation Steps

### Step 1: Configure Coverage in PHPUnit (30 minutes)
1. Update phpunit.xml with coverage settings
2. Configure coverage formats (HTML, Clover)
3. Set coverage thresholds

### Step 2: Generate Coverage Reports (30 minutes)
1. Run tests with coverage
2. Review generated reports
3. Identify uncovered areas

### Step 3: CI/CD Integration (30 minutes)
1. Add coverage to pipeline
2. Configure coverage badges
3. Set minimum coverage requirements

### Step 4: Documentation (30 minutes)
1. Update testing guide
2. Document coverage interpretation

## Code Changes

### File: `phpunit.xml`
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="./vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">./tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory suffix="Test.php">./tests/Feature</directory>
        </testsuite>
    </testsuites>
    <coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">./app</directory>
        </include>
        <exclude>
            <directory>./app/Console</directory>
            <file>./app/Http/Kernel.php</file>
        </exclude>
        <report>
            <html outputDirectory="reports/coverage/html"/>
            <clover outputFile="reports/coverage/coverage.xml"/>
            <text outputFile="reports/coverage/coverage.txt"/>
        </report>
    </coverage>
    <logging>
        <junit outputFile="reports/junit.xml"/>
    </logging>
</phpunit>
```

### New File: `reports/.gitkeep`
```bash
# Keep reports directory
mkdir -p reports/coverage
touch reports/.gitkeep
```

### File: `composer.json` (Add scripts)
```json
{
    "scripts": {
        "test": "phpunit",
        "test:coverage": "phpunit --coverage-html reports/coverage/html",
        "test:coverage:ci": "phpunit --coverage-clover reports/coverage/coverage.xml"
    }
}
```

## Verification Steps

1. **Coverage Generation:**
   ```bash
   composer run test:coverage
   ```

2. **Report Review:**
   - Open `reports/coverage/html/index.html`
   - Review coverage percentages
   - Identify low-coverage areas

3. **CI/CD Integration:**
   - Verify coverage reports generated
   - Check coverage thresholds

## Rollback Plan

If coverage causes issues:
1. Make coverage optional
2. Reduce coverage requirements
3. Use simpler coverage tools

## Testing Requirements

- [ ] Coverage reports generated
- [ ] HTML reports accessible
- [ ] CI/CD integration working
- [ ] Coverage thresholds configured

## Documentation Updates

- Document coverage requirements
- Update testing guide
- Mark as completed in master work plan

## Completion Criteria

- [ ] Coverage reporting configured
- [ ] Reports generated successfully
- [ ] CI/CD integration complete
- [ ] Code reviewed and approved

---

**Estimated Completion:** 1-2 hours
**Actual Time Spent:** ____ hours
**Completed By:** ____________
**Date:** ____________