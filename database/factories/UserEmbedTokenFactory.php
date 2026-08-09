<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserEmbedToken;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserEmbedTokenFactory extends Factory
{
    protected $model = UserEmbedToken::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organization_id' => Organization::factory(),
            'token' => hash('sha256', 'opbxd_'.fake()->unique()->sha1()),
            'icon_position' => 'bottom-right',
            'icon_background_color' => '#007acc',
        ];
    }
}
