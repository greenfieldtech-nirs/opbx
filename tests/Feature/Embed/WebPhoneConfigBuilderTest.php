<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\WebPhoneConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WebPhoneConfigBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_sip_config_for_user(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'name' => 'Jane']);
        Extension::factory()->create([
            'organization_id' => $org->id, 'user_id' => $user->id,
            'type' => 'user', 'extension_number' => '1001', 'password' => 'sipsecret',
        ]);
        CloudonixSettings::factory()->create(['organization_id' => $org->id, 'domain_name' => 'acme.cx']);

        $config = app(WebPhoneConfigBuilder::class)->buildForUser($user);

        $this->assertSame('1001', $config['sip_username']);
        $this->assertSame('sipsecret', $config['sip_password']);
        $this->assertSame('acme.cx', $config['sip_domain']);
        $this->assertSame('sip:1001@acme.cx', $config['sip_uri']);
        $this->assertSame('Jane', $config['display_name']);
    }

    public function test_returns_null_when_no_extension(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        CloudonixSettings::factory()->create(['organization_id' => $org->id, 'domain_name' => 'acme.cx']);

        $this->assertNull(app(WebPhoneConfigBuilder::class)->buildForUser($user));
    }
}
