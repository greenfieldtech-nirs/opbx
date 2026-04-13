# Auto Dialer - Phase 4 Fixes

## Fixes Applied

### 1. CampaignProcessor Refactoring
- **Changed**: Synchronous dialing to async job dispatch
- **Added**: `DialDestinationJob` dispatch with staggered delays based on CPS
- **Fixed**: Race condition in completion check - now waits for ALL destinations in final states
- **Removed**: CloudonixClient dependency (moved to DialDestinationJob)
- **Added**: `isCampaignComplete()` method to properly detect when all calls finished

### 2. DialDestinationJob Cleanup
- **Fixed**: Removed duplicate retry logic (custom + Laravel)
- **Changed**: Now uses Laravel's built-in retry mechanism only
- **Updated**: Backoff to exponential: 5min, 15min, 45min
- **Changed**: Throws exception on API failure to trigger retry
- **Removed**: `scheduleRetryIfNeeded()` method

### 3. ProcessAutoDialerCampaignJob Improvements
- **Added**: Queue specification (`auto-dialer`)
- **Fixed**: Rescheduling now uses try-finally to ensure next batch always scheduled
- **Changed**: Uses `fresh()` campaign instance before rescheduling
- **Added**: Proper queue naming for dispatched jobs

### 4. CampaignLifecycleManager Updates
- **Added**: Queue specification for all job dispatches
- Both `start()` and `resume()` now dispatch to `auto-dialer` queue

### 5. CampaignStatistics Locking
- **Added**: Database transaction with `lockForUpdate()`
- **Added**: Pessimistic locking when updating campaign counters
- **Added**: `DB` facade import
- Prevents race conditions when multiple jobs update stats simultaneously

### 6. Queue Configuration
- **Added**: Dedicated `auto-dialer` queue connection in `config/queue.php`
- **Features**:
  - Configurable via environment variables
  - Longer `retry_after` (3600s = 1 hour) for long-running campaigns
  - Defaults to Redis driver

## Environment Variables

Add these to your `.env` file:

```env
# Auto Dialer Queue Configuration
AUTO_DIALER_QUEUE_DRIVER=redis
AUTO_DIALER_QUEUE_CONNECTION=default
AUTO_DIALER_QUEUE=auto-dialer
AUTO_DIALER_QUEUE_RETRY_AFTER=3600
```

## Horizon Configuration (Required)

Laravel Horizon is recommended for monitoring the auto-dialer queue. Add to your `config/horizon.php`:

```php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default', 'auto-dialer'],
            'balance' => 'auto',
            'maxProcesses' => 10,
            'tries' => 3,
            'timeout' => 300,
        ],
    ],
],
```

And add job tagging in `app/Providers/HorizonServiceProvider.php`:

```php
$horizon->tags([
    \App\Jobs\ProcessAutoDialerCampaignJob::class => fn ($job) => ['campaign:' . $job->campaignId],
    \App\Jobs\DialDestinationJob::class => fn ($job) => ['destination:' . $job->destinationId],
]);
```

## Scheduler Configuration

Add to `app/Console/Kernel.php`:

```php
$schedule->command('auto-dialer:check-campaigns')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
```

## Testing

Run the auto-dialer check command:
```bash
php artisan auto-dialer:check-campaigns
```

Monitor the queue:
```bash
php artisan queue:work --queue=auto-dialer
```

With Horizon:
```bash
php artisan horizon
```

## Critical Changes Summary

1. **Jobs now dispatch to specific queue**: All auto-dialer jobs use `->onQueue('auto-dialer')`
2. **Rate limiting via staggered delays**: CPS controls timing via job delays, not sleep
3. **Proper completion detection**: Campaign only completes when all destinations reach final state
4. **Single retry mechanism**: Uses Laravel's built-in retry, not custom logic
5. **Database locking**: Statistics updates use pessimistic locking
6. **Queue isolation**: Dedicated queue prevents interference with other jobs

## Remaining Issues

- **Rate Limiter**: Consider adding Laravel RateLimiter for API call rate limiting if needed
- **Circuit Breaker**: CloudonixClient already has circuit breaker pattern implemented
- **Monitoring**: Install Horizon for production monitoring

## Commits

1. `bf3a2675` - Refactor CampaignProcessor
2. `3924c63b` - Remove duplicate retry logic
3. `ca678208` - Add queue specification and improve rescheduling
4. `1ae778b1` - Add locking to CampaignStatistics
5. `01345298` - Add queue configuration
