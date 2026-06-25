<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SocialIdentityProvider;
use App\Models\User;
use App\Models\UserSocialIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserSocialIdentityFactory extends Factory
{
    protected $model = UserSocialIdentity::class;

    public function definition(): array
    {
        $provider = $this->faker->randomElement(SocialIdentityProvider::cases());

        return [
            'user_id' => User::factory(),
            'provider' => $provider,
            'provider_subject' => $provider->auth0Connection().'|'.$this->faker->uuid(),
            'provider_email' => $this->faker->unique()->safeEmail(),
            'provider_data' => [],
        ];
    }
}
