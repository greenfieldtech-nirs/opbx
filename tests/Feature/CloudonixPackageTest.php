<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Models\User;
use App\Services\Cloudonix\CloudonixPackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CloudonixPackageTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN_UUID = '550e8400-e29b-41d4-a716-446655440000';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'domain_uuid' => self::DOMAIN_UUID,
            'domain_api_key' => 'test-api-key-12345678',
        ]);
    }

    private function domainUrl(): string
    {
        return config('cloudonix.api.base_url').'/customers/self/domains/'.self::DOMAIN_UUID;
    }

    private function fakeProfile(string $package): void
    {
        Http::fake([
            $this->domainUrl() => Http::response(['tenant' => ['billingPlan' => ['package' => ['name' => $package]]]]),
        ]);
    }

    private function user(UserRole $role): User
    {
        return User::create([
            'organization_id' => $this->organization->id,
            'name' => 'User',
            'email' => strtolower($role->name).'@example.com',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    public function test_get_fetches_once_then_serves_from_cache(): void
    {
        $this->fakeProfile('Business');
        $service = app(CloudonixPackageService::class);

        $this->assertSame('Business', $service->get($this->organization->id));
        $this->assertSame('Business', $service->get($this->organization->id));

        Http::assertSentCount(1);
        $this->assertSame('Business', Cache::get(CloudonixPackageService::cacheKey($this->organization->id)));
    }

    public function test_refresh_replaces_the_cached_value(): void
    {
        Cache::put(CloudonixPackageService::cacheKey($this->organization->id), 'Free Tier', 3600);
        $this->fakeProfile('Business');

        $this->assertSame('Business', app(CloudonixPackageService::class)->refresh($this->organization->id));
        $this->assertSame('Business', Cache::get(CloudonixPackageService::cacheKey($this->organization->id)));
    }

    public function test_unreachable_cloudonix_returns_null_and_caches_nothing(): void
    {
        Http::fake([$this->domainUrl() => Http::failedConnection()]);

        $this->assertNull(app(CloudonixPackageService::class)->get($this->organization->id));
        $this->assertFalse(Cache::has(CloudonixPackageService::cacheKey($this->organization->id)));
    }

    public function test_login_warms_the_cache(): void
    {
        $this->fakeProfile('Business');
        $this->user(UserRole::PBX_ADMIN);

        $this->postJson('/api/v1/auth/login', ['email' => 'pbx_admin@example.com', 'password' => 'password123'])
            ->assertOk();

        $this->assertSame('Business', Cache::get(CloudonixPackageService::cacheKey($this->organization->id)));
    }

    public function test_login_succeeds_when_cloudonix_is_unreachable(): void
    {
        Http::fake([$this->domainUrl() => Http::failedConnection()]);
        $this->user(UserRole::PBX_ADMIN);

        $this->postJson('/api/v1/auth/login', ['email' => 'pbx_admin@example.com', 'password' => 'password123'])
            ->assertOk();

        $this->assertFalse(Cache::has(CloudonixPackageService::cacheKey($this->organization->id)));
    }

    public function test_ivr_voices_endpoint_exposes_the_package_to_pbx_admin(): void
    {
        Cache::put('cloudonix-voices:'.self::DOMAIN_UUID, [
            ['id' => 'v1', 'name' => 'Voice', 'language' => 'en-US', 'gender' => 'female', 'premium' => false, 'pricing' => 'standard', 'provider' => 'Cloudonix'],
        ], 3600);
        Cache::put(CloudonixPackageService::cacheKey($this->organization->id), 'Business', 3600);

        Sanctum::actingAs($this->user(UserRole::PBX_ADMIN));
        $this->getJson('/api/v1/ivr-menus/voices')
            ->assertOk()
            ->assertJsonPath('cloudonix_package', 'Business');
    }

    public function test_settings_returns_the_live_package_not_the_db_column(): void
    {
        $this->fakeProfile('Business');

        Sanctum::actingAs($this->user(UserRole::OWNER));
        $this->getJson('/api/v1/settings/cloudonix')
            ->assertOk()
            ->assertJsonPath('settings.cloudonix_package', 'Business');
    }

    public function test_validate_credentials_refreshes_the_cached_package(): void
    {
        Cache::put(CloudonixPackageService::cacheKey($this->organization->id), 'Free Tier', 3600);
        $this->fakeProfile('Business');

        Sanctum::actingAs($this->user(UserRole::OWNER));
        $this->postJson('/api/v1/settings/cloudonix/validate', [
            'domain_uuid' => self::DOMAIN_UUID,
            'domain_api_key' => 'valid-api-key',
        ])->assertOk();

        $this->assertSame('Business', Cache::get(CloudonixPackageService::cacheKey($this->organization->id)));
        Http::assertSent(fn (HttpClientRequest $request): bool => $request->hasHeader('Authorization', 'Bearer valid-api-key'));
    }
}
