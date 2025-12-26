<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Profile management feature tests.
 *
 * Tests profile retrieval, updates, and password changes for authenticated users.
 * Validates security constraints, validation rules, and proper error handling.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a test user with active organization.
     *
     * @return User
     */
    private function createTestUser(): User
    {
        $organization = Organization::factory()->create([
            'status' => 'active',
        ]);

        return User::factory()->create([
            'organization_id' => $organization->id,
            'email' => 'test@example.com',
            'password' => Hash::make('OldPassword123!'),
            'role' => UserRole::ADMIN,
            'status' => 'active',
        ]);
    }

    /**
     * Test that authenticated user can get their profile.
     */
    public function test_authenticated_user_can_get_profile(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'organization_id',
                    'name',
                    'email',
                    'role',
                    'status',
                    'created_at',
                    'updated_at',
                    'organization' => [
                        'id',
                        'name',
                        'slug',
                        'status',
                        'timezone',
                    ],
                ],
            ])
            ->assertJson([
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                    'role' => $user->role->value,
                    'status' => $user->status,
                ],
            ]);
    }

    /**
     * Test that unauthenticated user cannot access profile.
     */
    public function test_unauthenticated_user_cannot_get_profile(): void
    {
        $response = $this->getJson('/api/v1/profile');

        $response->assertStatus(401);
    }

    /**
     * Test that user can update their profile successfully.
     */
    public function test_user_can_update_profile(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Profile updated successfully.',
                'user' => [
                    'name' => 'Updated Name',
                    'email' => 'updated@example.com',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);
    }

    /**
     * Test profile update validation for missing name.
     */
    public function test_profile_update_requires_name(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', [
                'email' => 'test@example.com',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Test profile update validation for missing email.
     */
    public function test_profile_update_requires_email(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', [
                'name' => 'Test User',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Test profile update validation for invalid email format.
     */
    public function test_profile_update_requires_valid_email(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', [
                'name' => 'Test User',
                'email' => 'invalid-email',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Test profile update validation for duplicate email.
     */
    public function test_profile_update_requires_unique_email(): void
    {
        $user = $this->createTestUser();

        // Create another user with a different email
        $otherUser = User::factory()->create([
            'organization_id' => $user->organization_id,
            'email' => 'other@example.com',
        ]);

        // Try to update current user's email to match other user's email
        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', [
                'name' => 'Test User',
                'email' => 'other@example.com',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * Test that user can keep their own email when updating.
     */
    public function test_user_can_keep_same_email_when_updating(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', [
                'name' => 'Updated Name',
                'email' => $user->email, // Keep same email
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'email' => $user->email,
        ]);
    }

    /**
     * Test profile update validation for name too long.
     */
    public function test_profile_update_validates_name_length(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', [
                'name' => str_repeat('a', 256), // Exceeds 255 char limit
                'email' => 'test@example.com',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Test that user can change password successfully.
     */
    public function test_user_can_change_password(): void
    {
        $user = $this->createTestUser();

        // Create a token to verify it gets revoked
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'OldPassword123!',
                'new_password' => 'NewPassword456!',
                'new_password_confirmation' => 'NewPassword456!',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Password updated successfully. Please log in again with your new password.',
            ]);

        // Verify password was changed
        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword456!', $user->password));

        // Verify all tokens were revoked
        $this->assertEquals(0, $user->tokens()->count());
    }

    /**
     * Test password change fails with wrong current password.
     */
    public function test_password_change_fails_with_wrong_current_password(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'WrongPassword123!',
                'new_password' => 'NewPassword456!',
                'new_password_confirmation' => 'NewPassword456!',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        // Verify password was not changed
        $user->refresh();
        $this->assertTrue(Hash::check('OldPassword123!', $user->password));
    }

    /**
     * Test password change requires current password.
     */
    public function test_password_change_requires_current_password(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/password', [
                'new_password' => 'NewPassword456!',
                'new_password_confirmation' => 'NewPassword456!',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    /**
     * Test password change requires new password.
     */
    public function test_password_change_requires_new_password(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'OldPassword123!',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    /**
     * Test password change requires confirmation.
     */
    public function test_password_change_requires_confirmation(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'OldPassword123!',
                'new_password' => 'NewPassword456!',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    /**
     * Test password change validates confirmation matches.
     */
    public function test_password_change_validates_confirmation_matches(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'OldPassword123!',
                'new_password' => 'NewPassword456!',
                'new_password_confirmation' => 'DifferentPassword789!',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    /**
     * Test password change enforces minimum length.
     */
    public function test_password_change_enforces_minimum_length(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'OldPassword123!',
                'new_password' => 'Short1!',
                'new_password_confirmation' => 'Short1!',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    /**
     * Test password change requires mixed case.
     */
    public function test_password_change_requires_mixed_case(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'OldPassword123!',
                'new_password' => 'lowercase123!',
                'new_password_confirmation' => 'lowercase123!',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    /**
     * Test password change requires numbers.
     */
    public function test_password_change_requires_numbers(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'OldPassword123!',
                'new_password' => 'NewPassword!',
                'new_password_confirmation' => 'NewPassword!',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    /**
     * Test password change requires symbols.
     */
    public function test_password_change_requires_symbols(): void
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'OldPassword123!',
                'new_password' => 'NewPassword123',
                'new_password_confirmation' => 'NewPassword123',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    /**
     * Test unauthenticated user cannot change password.
     */
    public function test_unauthenticated_user_cannot_change_password(): void
    {
        $response = $this->putJson('/api/v1/profile/password', [
            'current_password' => 'OldPassword123!',
            'new_password' => 'NewPassword456!',
            'new_password_confirmation' => 'NewPassword456!',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test unauthenticated user cannot update profile.
     */
    public function test_unauthenticated_user_cannot_update_profile(): void
    {
        $response = $this->putJson('/api/v1/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(401);
    }
}
