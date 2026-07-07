<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationJoinRequestStatus;
use App\Enums\SocialIdentityProvider;
use App\Models\Organization;
use App\Models\OrganizationJoinRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationJoinRequestFactory extends Factory
{
    protected $model = OrganizationJoinRequest::class;

    public function definition(): array
    {
        $provider = $this->faker->randomElement(SocialIdentityProvider::cases());

        return [
            'organization_id' => Organization::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'name' => $this->faker->name(),
            'provider' => $provider,
            'provider_subject' => $provider->auth0Connection().'|'.$this->faker->uuid(),
            'status' => OrganizationJoinRequestStatus::PENDING,
            'role' => 'pbx_user',
        ];
    }
}
