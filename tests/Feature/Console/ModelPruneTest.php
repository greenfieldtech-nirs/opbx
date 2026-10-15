<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AutoDialerCallSession;
use App\Models\BlockedCallLog;
use App\Models\CallNotificationLog;
use App\Models\CallTrackingSession;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\QueueCall;
use App\Models\SessionUpdate;
use App\Scopes\OrganizationScope;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModelPruneTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class-string, string, string, int}> */
    public static function models(): array
    {
        return [
            'session_updates' => [SessionUpdate::class, 'session_updates_days', 'updated_at', 30],
            'call_notification_logs' => [CallNotificationLog::class, 'call_notification_logs_days', 'created_at', 90],
            'email_logs' => [EmailLog::class, 'email_logs_days', 'created_at', 90],
            'blocked_call_logs' => [BlockedCallLog::class, 'blocked_call_logs_days', 'blocked_at', 90],
            'queue_calls' => [QueueCall::class, 'queue_calls_days', 'created_at', 183],
            'call_tracking_sessions' => [CallTrackingSession::class, 'call_tracking_sessions_days', 'created_at', 183],
            'auto_dialer_call_sessions' => [AutoDialerCallSession::class, 'auto_dialer_call_sessions_days', 'created_at', 183],
            'platform_audit_logs' => [PlatformAuditLog::class, 'platform_audit_logs_days', 'created_at', 365],
        ];
    }

    #[DataProvider('models')]
    public function test_prunable_selects_only_rows_past_the_window(string $class, string $key, string $column, int $days): void
    {
        $this->assertSame($days, config("retention.$key"));

        $old = $this->makeRow($class, $column, now()->subDays($days + 1));
        $new = $this->makeRow($class, $column, now()->subDays($days - 1));

        $ids = (new $class)->prunable()->pluck((new $class)->getKeyName())->all();

        $this->assertSame([$old], $ids);
        $this->assertNotContains($new, $ids);
    }

    #[DataProvider('models')]
    public function test_null_retention_selects_nothing(string $class, string $key, string $column, int $days): void
    {
        $this->makeRow($class, $column, now()->subYears(10));
        config(["retention.$key" => null]);

        $this->assertSame(0, (new $class)->prunable()->count());
    }

    public function test_model_prune_is_scheduled_daily_at_three(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'model:prune'));

        $this->assertNotNull($event);
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertStringContainsString('PlatformAuditLog', $event->command);
        $this->assertStringNotContainsString('CallDetailRecord', $event->command);
    }

    private function makeRow(string $class, string $column, Carbon $at): int
    {
        $table = (new $class)->getTable();

        if ($class === EmailLog::class) {
            $id = DB::table($table)->insertGetId([
                'correlation_id' => 'c', 'provider' => 'p', 'driver' => 'd', 'from_email' => 'a@b.c',
                'to_email' => 'a@b.c', 'subject' => 's',
            ]);
        } elseif ($class === BlockedCallLog::class) {
            $id = DB::table($table)->insertGetId([
                'organization_id' => Organization::factory()->create()->id, 'caller_id' => '1', 'called_number' => '2',
                'rejection_strategy' => 'drop', 'blocked_at' => now(),
            ]);
        } else {
            // Some factories lazy-load tenant-scoped relations, which need the scope bypassed outside a request.
            $id = OrganizationScope::bypass(fn () => $class::factory()->create()->getKey());
        }

        // Query builder bypasses model timestamps so the age is exactly what the test sets.
        DB::table($table)->where((new $class)->getKeyName(), $id)->update([$column => $at]);

        return (int) $id;
    }
}
