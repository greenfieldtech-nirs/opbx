<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\CallNotificationLog;
use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_call_notification_logs_are_scoped_to_the_authenticated_organization(): void
    {
        $mine = Organization::factory()->create();
        $other = Organization::factory()->create();
        $log = CallNotificationLog::factory()->create(['organization_id' => $mine->id]);
        CallNotificationLog::factory()->create(['organization_id' => $other->id]);

        $this->assertSame(0, CallNotificationLog::count(), 'no user: fail closed');

        $this->actingAs(User::factory()->create(['organization_id' => $mine->id]));
        $this->assertSame([$log->id], CallNotificationLog::pluck('id')->all());

        $this->assertSame(2, OrganizationScope::bypass(fn () => CallNotificationLog::count()));
    }
}
