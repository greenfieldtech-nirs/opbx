<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\EmailValidation\Contracts\EmailValidatorInterface;
use App\Services\EmailValidation\DTOs\EmailValidationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ponytail: the registration endpoint uses a real-time email validation
        // service. In tests that service is unreachable, so bind a fake validator
        // that marks every email as valid without making network calls.
        $this->app->bind(EmailValidatorInterface::class, fn () => new class implements EmailValidatorInterface
        {
            public function validate(string $email): EmailValidationResult
            {
                return new EmailValidationResult(isValid: true, checkedEmail: $email);
            }
        });
    }

    public function test_successful_registration(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'organization' => [
                'name' => 'Test Company',
                'timezone' => 'America/New_York',
            ],
            'admin' => [
                'name' => 'Test Admin',
                'email' => 'admin@test.com',
                'password' => 'SecureP@ss123!',
                'password_confirmation' => 'SecureP@ss123!',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'user' => [
                    'id',
                    'organization_id',
                    'name',
                    'email',
                    'role',
                    'status',
                ],
                'organization' => [
                    'id',
                    'name',
                    'slug',
                    'status',
                    'timezone',
                ],
                'access_token',
                'token_type',
                'expires_in',
            ])
            ->assertJson([
                'message' => 'Organization registered successfully',
                'user' => [
                    'name' => 'Test Admin',
                    'email' => 'admin@test.com',
                    'role' => 'owner',
                    'status' => 'active',
                ],
                'organization' => [
                    'name' => 'Test Company',
                    'slug' => 'test-company',
                    'status' => 'active',
                    'timezone' => 'America/New_York',
                ],
                'token_type' => 'Bearer',
            ]);

        $this->assertDatabaseHas('organizations', [
            'name' => 'Test Company',
            'slug' => 'test-company',
            'status' => 'active',
            'timezone' => 'America/New_York',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@test.com',
            'role' => UserRole::OWNER->value,
            'status' => UserStatus::ACTIVE->value,
        ]);
    }

    public function test_registration_with_custom_slug(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'organization' => [
                'name' => 'Test Company',
                'timezone' => 'America/New_York',
                'slug' => 'my-custom-slug',
            ],
            'admin' => [
                'name' => 'Test Admin',
                'email' => 'admin@test.com',
                'password' => 'SecureP@ss123!',
                'password_confirmation' => 'SecureP@ss123!',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('organization.slug', 'my-custom-slug');
    }

    public function test_duplicate_organization_name_fails(): void
    {
        Organization::factory()->create([
            'name' => 'Existing Company',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'organization' => [
                'name' => 'Existing Company',
                'timezone' => 'America/New_York',
            ],
            'admin' => [
                'name' => 'Test Admin',
                'email' => 'admin@test.com',
                'password' => 'SecureP@ss123!',
                'password_confirmation' => 'SecureP@ss123!',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['organization.name']);
    }

    public function test_duplicate_email_fails(): void
    {
        User::factory()->create([
            'email' => 'existing@test.com',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'organization' => [
                'name' => 'Test Company',
                'timezone' => 'America/New_York',
            ],
            'admin' => [
                'name' => 'Test Admin',
                'email' => 'existing@test.com',
                'password' => 'SecureP@ss123!',
                'password_confirmation' => 'SecureP@ss123!',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['admin.email']);
    }

    public function test_password_complexity_enforced(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'organization' => [
                'name' => 'Test Company',
                'timezone' => 'America/New_York',
            ],
            'admin' => [
                'name' => 'Test Admin',
                'email' => 'admin@test.com',
                'password' => 'weak',
                'password_confirmation' => 'weak',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['admin.password']);
    }

    public function test_password_mismatch_fails(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'organization' => [
                'name' => 'Test Company',
                'timezone' => 'America/New_York',
            ],
            'admin' => [
                'name' => 'Test Admin',
                'email' => 'admin@test.com',
                'password' => 'SecureP@ss123!',
                'password_confirmation' => 'DifferentP@ss123!',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['admin.password']);
    }

    public function test_invalid_timezone_fails(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'organization' => [
                'name' => 'Test Company',
                'timezone' => 'Invalid/Timezone',
            ],
            'admin' => [
                'name' => 'Test Admin',
                'email' => 'admin@test.com',
                'password' => 'SecureP@ss123!',
                'password_confirmation' => 'SecureP@ss123!',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['organization.timezone']);
    }

    public function test_required_fields_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'organization.name',
                'organization.timezone',
                'admin.name',
                'admin.email',
                'admin.password',
            ]);
    }

    public function test_organization_slug_generated_from_name(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'organization' => [
                'name' => 'Test Company with Spaces',
                'timezone' => 'America/New_York',
            ],
            'admin' => [
                'name' => 'Test Admin',
                'email' => 'admin@test.com',
                'password' => 'SecureP@ss123!',
                'password_confirmation' => 'SecureP@ss123!',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('organization.slug', 'test-company-with-spaces');
    }

    public function test_registration_creates_owner_user(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'organization' => [
                'name' => 'Test Company',
                'timezone' => 'America/New_York',
            ],
            'admin' => [
                'name' => 'Test Admin',
                'email' => 'admin@test.com',
                'password' => 'SecureP@ss123!',
                'password_confirmation' => 'SecureP@ss123!',
            ],
        ]);

        $response->assertStatus(201);

        $user = OrganizationScope::bypass(fn () => User::where('email', 'admin@test.com')->first());
        $this->assertNotNull($user);
        $this->assertEquals(UserRole::OWNER, $user->role);
        $this->assertEquals(UserStatus::ACTIVE, $user->status);
        $this->assertNotEmpty($user->password);
    }

    public function test_validate_registration_endpoint_organization_name(): void
    {
        Organization::factory()->create([
            'name' => 'Existing Company',
        ]);

        $response = $this->getJson('/api/v1/auth/register/validate?organization_name=Existing Company');

        $response->assertStatus(200)
            ->assertJsonPath('data.organization_name_available', false);

        $response = $this->getJson('/api/v1/auth/register/validate?organization_name=New Company');

        $response->assertStatus(200)
            ->assertJsonPath('data.organization_name_available', true);
    }

    public function test_validate_registration_endpoint_email(): void
    {
        User::factory()->create([
            'email' => 'existing@test.com',
        ]);

        $response = $this->getJson('/api/v1/auth/register/validate?admin_email=existing@test.com');

        $response->assertStatus(200)
            ->assertJsonPath('data.admin_email_available', false);

        $response = $this->getJson('/api/v1/auth/register/validate?admin_email=new@test.com');

        $response->assertStatus(200)
            ->assertJsonPath('data.admin_email_available', true);
    }

    public function test_validate_registration_endpoint_slug(): void
    {
        Organization::factory()->create([
            'slug' => 'existing-slug',
        ]);

        $response = $this->getJson('/api/v1/auth/register/validate?slug=existing-slug');

        $response->assertStatus(200)
            ->assertJsonPath('data.slug_available', false);

        $response = $this->getJson('/api/v1/auth/register/validate?slug=new-slug');

        $response->assertStatus(200)
            ->assertJsonPath('data.slug_available', true);
    }
}
