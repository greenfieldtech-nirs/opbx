<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetUserPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_prompted_not_taken_as_an_argument(): void
    {
        $user = User::factory()->create();

        $this->artisan('user:reset-password', ['email' => $user->email, '--force' => true])
            ->expectsQuestion('New password', 'Sup3r-secret-pass')
            ->expectsQuestion('Confirm new password', 'Sup3r-secret-pass')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('Sup3r-secret-pass', $user->fresh()->password));
    }

    public function test_mismatching_confirmation_changes_nothing(): void
    {
        $user = User::factory()->create();
        $before = $user->password;

        $this->artisan('user:reset-password', ['email' => $user->email, '--force' => true])
            ->expectsQuestion('New password', 'one')
            ->expectsQuestion('Confirm new password', 'two')
            ->assertFailed();

        $this->assertSame($before, $user->fresh()->password);
    }
}
