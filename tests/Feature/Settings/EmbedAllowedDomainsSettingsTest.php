<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EmbedAllowedDomainsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $org = Organization::factory()->create();

        return User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'no_answer_timeout' => 30,
            'recording_format' => 'wav',
        ], $overrides);
    }

    public function test_owner_can_set_embed_allowed_domains(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->putJson('/api/v1/settings/cloudonix', $this->validPayload([
                'embed_allowed_domains' => ['crm.acme.com', 'app.acme.com'],
            ]))
            ->assertOk();

        $settings = CloudonixSettings::where('organization_id', $owner->organization_id)->first();
        $this->assertSame(['crm.acme.com', 'app.acme.com'], $settings->embed_allowed_domains);
    }

    public function test_get_returns_embed_allowed_domains(): void
    {
        $owner = $this->owner();
        CloudonixSettings::factory()->create([
            'organization_id' => $owner->organization_id,
            'embed_allowed_domains' => ['crm.acme.com'],
        ]);

        $this->actingAs($owner)
            ->getJson('/api/v1/settings/cloudonix')
            ->assertOk()
            ->assertJsonPath('settings.embed_allowed_domains', ['crm.acme.com']);
    }

    public function test_invalid_hostname_is_rejected(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->putJson('/api/v1/settings/cloudonix', $this->validPayload([
                'embed_allowed_domains' => ['not a domain'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('embed_allowed_domains.0');
    }

    /**
     * @dataProvider validDevDomainProvider
     */
    public function test_dev_domains_with_localhost_ip_and_port_are_accepted(string $domain): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->putJson('/api/v1/settings/cloudonix', $this->validPayload([
                'embed_allowed_domains' => [$domain],
            ]))
            ->assertOk();

        $settings = CloudonixSettings::where('organization_id', $owner->organization_id)->first();
        $this->assertSame([$domain], $settings->embed_allowed_domains);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validDevDomainProvider(): array
    {
        return [
            'localhost' => ['localhost'],
            'localhost with port' => ['localhost:3000'],
            'ipv4' => ['192.168.2.240'],
            'ipv4 with port' => ['127.0.0.1:3000'],
            'hostname with port' => ['crm.acme.com:8443'],
        ];
    }

    /**
     * @dataProvider invalidDomainProvider
     */
    public function test_invalid_domains_are_rejected(string $domain): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->putJson('/api/v1/settings/cloudonix', $this->validPayload([
                'embed_allowed_domains' => [$domain],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('embed_allowed_domains.0');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDomainProvider(): array
    {
        return [
            'space' => ['not a domain'],
            'scheme included' => ['http://crm.acme.com'],
            'path included' => ['crm.acme.com/foo'],
            'port too long' => ['localhost:123456'],
        ];
    }
}
