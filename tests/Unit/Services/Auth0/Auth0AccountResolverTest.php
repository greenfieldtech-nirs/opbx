<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth0;

use App\Enums\SocialIdentityProvider;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserSocialIdentity;
use App\Scopes\OrganizationScope;
use App\Services\Auth0\Auth0AccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Auth0AccountResolverTest extends TestCase
{
    use RefreshDatabase;

    private Auth0AccountResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new Auth0AccountResolver;
    }

    public function test_resolve_returns_login_for_existing_identity(): void
    {
        $identity = UserSocialIdentity::factory()->create();
        $profile = [
            'provider' => $identity->provider->value,
            'subject' => $identity->provider_subject,
            'email_verified' => true,
        ];

        $result = $this->resolver->resolve($profile);

        $this->assertSame('login', $result['action']);
        $this->assertTrue($identity->user->is($result['user']));
    }

    public function test_resolve_returns_account_exists_for_existing_email(): void
    {
        $user = User::factory()->create();
        $profile = [
            'provider' => SocialIdentityProvider::GOOGLE->value,
            'subject' => 'google-oauth2|999',
            'email' => $user->email,
            'email_verified' => true,
        ];

        $result = $this->resolver->resolve($profile);

        $this->assertSame('account_exists', $result['action']);
        $this->assertTrue($user->is($result['user']));
    }

    public function test_resolve_returns_new_user_for_unknown_profile(): void
    {
        $profile = [
            'provider' => SocialIdentityProvider::GOOGLE->value,
            'subject' => 'google-oauth2|999',
            'email' => 'new@example.com',
            'email_verified' => true,
        ];

        $result = $this->resolver->resolve($profile);

        $this->assertSame('new_user', $result['action']);
    }

    public function test_create_organization_and_user_creates_owner(): void
    {
        $profile = [
            'provider' => SocialIdentityProvider::GOOGLE->value,
            'subject' => 'google-oauth2|999',
            'email' => 'owner@example.com',
            'name' => 'Owner',
            'email_verified' => true,
            'raw' => [],
        ];

        $user = $this->resolver->createOrganizationAndUser($profile);

        $this->assertSame('owner@example.com', $user->email);
        $this->assertTrue($user->isOwner());
        $this->assertInstanceOf(Organization::class, OrganizationScope::bypass(fn () => $user->organization));
        $this->assertDatabaseHas('user_social_identities', [
            'user_id' => $user->id,
            'provider_subject' => 'google-oauth2|999',
        ]);
    }
}
