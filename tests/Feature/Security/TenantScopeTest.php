<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\CallNotificationLog;
use App\Models\CloudonixSettings;
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

    public function test_cloudonix_settings_are_scoped_but_explicit_lookups_still_work_without_a_user(): void
    {
        $mine = Organization::factory()->create();
        $other = Organization::factory()->create();
        $mineSettings = CloudonixSettings::factory()->create(['organization_id' => $mine->id, 'domain_name' => 'mine.example']);
        CloudonixSettings::factory()->create(['organization_id' => $other->id, 'domain_name' => 'other.example']);

        $this->assertSame(0, CloudonixSettings::count(), 'no user: fail closed');

        // Webhooks, jobs and workers resolve by an organization or domain they already trust.
        $this->assertSame($mineSettings->id, CloudonixSettings::forOrganization($mine->id)?->id);
        $this->assertSame($mineSettings->id, CloudonixSettings::forDomain('domain_name', 'mine.example')?->id);
        $this->assertSame($mineSettings->id, $mine->cloudonixSettings?->id);

        $this->actingAs(User::factory()->create(['organization_id' => $mine->id]));
        $this->assertSame([$mineSettings->id], CloudonixSettings::pluck('id')->all());
    }
}
