<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrunkControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $admin;

    private User $pbxUser;

    private CloudonixSettings $settings;

    private string $trunksUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => 'active']);
        $this->owner = User::factory()->owner()->create(['organization_id' => $this->organization->id]);
        $this->admin = User::factory()->admin()->create(['organization_id' => $this->organization->id]);
        $this->pbxUser = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->settings = CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'domain_uuid' => 'dom-uuid-123',
        ]);

        $this->trunksUrl = rtrim((string) config('cloudonix.api.base_url'), '/')
            .'/customers/self/domains/dom-uuid-123/trunks';
    }

    /** @return array<string, mixed> */
    private function trunkFixture(array $overrides = []): array
    {
        return array_merge([
            'id' => 101,
            'uuid' => 'trunk-uuid-1',
            'name' => 'carrier-a',
            'direction' => 'outbound',
            'ip' => '203.0.113.10',
            'port' => 5060,
            'transport' => 'udp',
            'prefix' => null,
            'active' => true,
            'createdAt' => '2026-01-01T00:00:00Z',
            'profile' => [
                'authentication' => [
                    'username' => 'sipuser',
                    'password' => 'super-secret',
                    'overwrite-from' => false,
                ],
            ],
        ], $overrides);
    }

    public function test_owner_can_list_trunks_and_password_is_never_serialized(): void
    {
        Http::fake([
            $this->trunksUrl => Http::response([
                $this->trunkFixture(),
                $this->trunkFixture([
                    'id' => 102,
                    'uuid' => 'trunk-uuid-2',
                    'name' => 'public',
                    'direction' => 'public-outbound',
                    'profile' => [],
                ]),
            ]),
        ]);

        Sanctum::actingAs($this->owner);
        $response = $this->getJson('/api/v1/trunks');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertStringNotContainsString('super-secret', $response->getContent());

        $data = $response->json('data');
        // Direction passes through unchanged — no read_only flag, all trunks are equal
        $public = collect($data)->firstWhere('direction', 'public-outbound');
        $this->assertSame('public-outbound', $public['direction']);
        $this->assertFalse($public['has_credentials']);

        $regular = collect($data)->firstWhere('name', 'carrier-a');
        $this->assertSame('outbound', $regular['direction']);
        $this->assertTrue($regular['has_credentials']);
        $this->assertSame('sipuser', $regular['username']);
    }

    public function test_pbx_admin_can_list_trunks(): void
    {
        Http::fake([$this->trunksUrl => Http::response([$this->trunkFixture()])]);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/trunks')->assertOk();
    }

    public function test_index_includes_sip_hostname_meta(): void
    {
        Http::fake([$this->trunksUrl => Http::response([$this->trunkFixture()])]);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/trunks')
            ->assertOk()
            ->assertJsonPath('meta.sip_hostname', 'dom-uuid-123.sip.cloudonix.net');
    }

    public function test_pbx_user_cannot_list_trunks(): void
    {
        Http::fake([$this->trunksUrl => Http::response([$this->trunkFixture()])]);

        Sanctum::actingAs($this->pbxUser);
        $this->getJson('/api/v1/trunks')->assertForbidden();
    }

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/v1/trunks')->assertUnauthorized();
    }

    public function test_owner_can_create_trunk(): void
    {
        Http::fake([
            $this->trunksUrl => Http::response($this->trunkFixture(['name' => 'new-trunk']), 201),
        ]);

        Sanctum::actingAs($this->owner);
        $response = $this->postJson('/api/v1/trunks', [
            'name' => 'new-trunk',
            'direction' => 'outbound',
            'ip' => '198.51.100.5',
            'port' => 5061,
            'transport' => 'tls',
            'username' => 'auth-user',
            'password' => 'auth-pass',
            'overwrite_from' => true,
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'new-trunk');
        $this->assertStringNotContainsString('auth-pass', $response->getContent());

        Http::assertSent(function (HttpClientRequest $request): bool {
            if ($request->method() !== 'POST' || $request->url() !== $this->trunksUrl) {
                return false;
            }

            $auth = $request['profile']['authentication'] ?? null;

            return $request['name'] === 'new-trunk'
                && $request['direction'] === 'outbound'
                && $auth !== null
                && $auth['username'] === 'auth-user'
                && $auth['password'] === 'auth-pass'
                && $auth['overwrite-from'] === true;
        });
    }

    public function test_create_validation_errors(): void
    {
        Sanctum::actingAs($this->owner);

        $base = [
            'name' => 'valid-name',
            'direction' => 'outbound',
            'ip' => '203.0.113.1',
            'port' => 5060,
            'transport' => 'udp',
        ];

        $this->postJson('/api/v1/trunks', array_merge($base, ['transport' => 'sctp']))
            ->assertUnprocessable()->assertJsonValidationErrors('transport');

        $this->postJson('/api/v1/trunks', array_merge($base, ['port' => 99999]))
            ->assertUnprocessable()->assertJsonValidationErrors('port');

        $this->postJson('/api/v1/trunks', array_merge($base, ['username' => 'nouser']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/trunks', array_merge($base, ['direction' => 'public-inbound']))
            ->assertUnprocessable()->assertJsonValidationErrors('direction');

        $this->postJson('/api/v1/trunks', array_merge($base, ['ip' => 'not an ip!!']))
            ->assertUnprocessable()->assertJsonValidationErrors('ip');
    }

    public function test_pbx_user_cannot_create_trunk(): void
    {
        Sanctum::actingAs($this->pbxUser);

        $this->postJson('/api/v1/trunks', [
            'name' => 'new-trunk',
            'direction' => 'outbound',
            'ip' => '198.51.100.5',
            'port' => 5060,
            'transport' => 'udp',
        ])->assertForbidden();
    }

    public function test_can_update_public_outbound_trunk(): void
    {
        // getTrunk GET fires first, then the PUT on the same URL
        Http::fake([
            $this->trunksUrl.'/9' => Http::sequence()
                ->push($this->trunkFixture(['direction' => 'public-outbound']))
                ->push($this->trunkFixture(['direction' => 'public-outbound', 'port' => 5070])),
        ]);

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/trunks/9', ['port' => 5070])
            ->assertOk()
            ->assertJsonPath('data.direction', 'public-outbound');

        Http::assertSent(fn (HttpClientRequest $request): bool => $request->method() === 'PUT');
    }

    public function test_can_delete_public_inbound_trunk(): void
    {
        // getTrunk GET fires first, then the DELETE on the same URL
        Http::fake([
            $this->trunksUrl.'/9' => Http::sequence()
                ->push($this->trunkFixture(['direction' => 'public-inbound']))
                ->push(null, 204),
        ]);

        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/v1/trunks/9')->assertNoContent();
    }

    public function test_delete_trunk_success(): void
    {
        // getTrunk GET fires first, then the DELETE on the same URL
        Http::fake([
            $this->trunksUrl.'/101' => Http::sequence()
                ->push($this->trunkFixture())
                ->push(null, 204),
        ]);

        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/v1/trunks/101')->assertNoContent();
    }

    public function test_in_use_by_populated_from_outbound_whitelist(): void
    {
        OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'US Mobiles',
            'outbound_trunk_name' => 'carrier-a',
        ]);

        Http::fake([
            $this->trunksUrl => Http::response([
                $this->trunkFixture(),
                $this->trunkFixture(['id' => 102, 'name' => 'carrier-b']),
            ]),
        ]);

        Sanctum::actingAs($this->owner);
        $data = $this->getJson('/api/v1/trunks')->assertOk()->json('data');

        $this->assertSame(['US Mobiles'], collect($data)->firstWhere('name', 'carrier-a')['in_use_by']);
        $this->assertSame([], collect($data)->firstWhere('name', 'carrier-b')['in_use_by']);
    }

    public function test_cloudonix_failure_on_list_returns_502(): void
    {
        Http::fake([$this->trunksUrl => Http::response('boom', 500)]);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/trunks')
            ->assertStatus(502)
            ->assertJsonPath('error', 'cloudonix_unavailable');
    }

    public function test_update_without_password_omits_password_field(): void
    {
        Http::fake([
            $this->trunksUrl.'/101' => Http::sequence()
                ->push($this->trunkFixture())
                ->push($this->trunkFixture(['port' => 5080])),
        ]);

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/trunks/101', [
            'name' => 'rename-attempt',
            'direction' => 'inbound',
            'port' => 5080,
            'username' => 'sipuser',
            'overwrite_from' => true,
        ])->assertOk();

        Http::assertSent(function (HttpClientRequest $request): bool {
            if ($request->method() !== 'PUT') {
                return true; // only inspect the PUT
            }

            $auth = $request['profile']['authentication'] ?? [];
            $this->assertArrayNotHasKey('password', $auth);
            $this->assertSame('sipuser', $auth['username']);
            $this->assertTrue($auth['overwrite-from']);
            $this->assertSame(5080, $request['port']);
            $this->assertArrayNotHasKey('name', $request->data());
            $this->assertArrayNotHasKey('direction', $request->data());

            return true;
        });
    }

    public function test_show_trunk(): void
    {
        Http::fake([
            $this->trunksUrl.'/101' => Http::response($this->trunkFixture()),
        ]);

        Sanctum::actingAs($this->owner);
        $response = $this->getJson('/api/v1/trunks/101');

        $response->assertOk()->assertJsonPath('data.name', 'carrier-a');
        $this->assertStringNotContainsString('super-secret', $response->getContent());
    }

    public function test_show_missing_trunk_returns_404(): void
    {
        Http::fake([
            $this->trunksUrl.'/999' => Http::response('not found', 404),
        ]);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/trunks/999')->assertNotFound();
    }

    public function test_show_returns_502_when_cloudonix_fails(): void
    {
        Http::fake([
            $this->trunksUrl.'/101' => Http::response('boom', 500),
        ]);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/trunks/101')
            ->assertStatus(502)
            ->assertJsonPath('error', 'cloudonix_unavailable');
    }

    public function test_update_with_password_without_username_returns_422(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/trunks/101', ['password' => 'new-secret'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }
}
