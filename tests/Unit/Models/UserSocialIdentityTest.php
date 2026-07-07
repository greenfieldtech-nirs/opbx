<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\UserSocialIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSocialIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_user(): void
    {
        $identity = UserSocialIdentity::factory()->create();

        $this->assertInstanceOf(User::class, $identity->user);
    }
}
