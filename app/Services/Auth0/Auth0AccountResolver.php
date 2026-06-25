<?php

declare(strict_types=1);

namespace App\Services\Auth0;

use App\Enums\OrganizationJoinRequestStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\OrganizationJoinRequest;
use App\Models\User;
use App\Models\UserSocialIdentity;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Auth0AccountResolver
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function resolve(array $profile): array
    {
        if (! ($profile['email_verified'] ?? false)) {
            return ['action' => 'email_unverified'];
        }

        $identity = UserSocialIdentity::withoutGlobalScope(OrganizationScope::class)
            ->where('provider', $profile['provider'])
            ->where('provider_subject', $profile['subject'])
            ->first();

        if ($identity !== null) {
            return ['action' => 'login', 'user' => $identity->user];
        }

        $existingUser = User::withoutGlobalScope(OrganizationScope::class)
            ->where('email', $profile['email'])
            ->first();

        if ($existingUser !== null) {
            return ['action' => 'account_exists', 'user' => $existingUser];
        }

        return ['action' => 'new_user', 'profile' => $profile];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public function createOrganizationAndUser(array $profile): User
    {
        return OrganizationScope::bypass(function () use ($profile) {
            $organization = Organization::create([
                'name' => $profile['email'].' Organization',
                'slug' => $this->generateSlug($profile['email']),
                'status' => 'active',
                'timezone' => 'UTC',
                'settings' => [],
            ]);

            $user = User::create([
                'organization_id' => $organization->id,
                'name' => $profile['name'] ?: $profile['email'],
                'email' => $profile['email'],
                'password' => Hash::make(Str::random(32)),
                'role' => UserRole::OWNER,
                'status' => UserStatus::ACTIVE,
            ]);

            UserSocialIdentity::create([
                'user_id' => $user->id,
                'provider' => $profile['provider'],
                'provider_subject' => $profile['subject'],
                'provider_email' => $profile['email'],
                'provider_data' => $profile['raw'] ?? [],
            ]);

            return $user;
        });
    }

    public function createJoinRequest(string $organizationSlug, array $profile): OrganizationJoinRequest
    {
        $organization = Organization::where('slug', $organizationSlug)->where('status', 'active')->first();

        if ($organization === null) {
            throw new ModelNotFoundException('Organization not found.');
        }

        return OrganizationJoinRequest::create([
            'organization_id' => $organization->id,
            'email' => $profile['email'],
            'name' => $profile['name'] ?: $profile['email'],
            'provider' => $profile['provider'],
            'provider_subject' => $profile['subject'],
            'status' => OrganizationJoinRequestStatus::PENDING,
            'role' => 'pbx_user',
        ]);
    }

    public function linkIdentity(User $user, array $profile): UserSocialIdentity
    {
        return UserSocialIdentity::create([
            'user_id' => $user->id,
            'provider' => $profile['provider'],
            'provider_subject' => $profile['subject'],
            'provider_email' => $profile['email'],
            'provider_data' => $profile['raw'] ?? [],
        ]);
    }

    /**
     * Activate a pending user and bind their Auth0 identity from an invitation.
     *
     * @param  array<string, mixed>  $profile
     *
     * @throws \RuntimeException
     */
    public function resolveInvitation(User $pendingUser, array $profile): User
    {
        if (! ($profile['email_verified'] ?? false)) {
            throw new \RuntimeException('Email not verified.');
        }

        if ($pendingUser->email !== $profile['email']) {
            throw new \RuntimeException('Email does not match invitation.');
        }

        $pendingUser->status = UserStatus::ACTIVE;
        $pendingUser->name = $profile['name'] ?: $pendingUser->name;
        $pendingUser->save();

        UserSocialIdentity::create([
            'user_id' => $pendingUser->id,
            'provider' => $profile['provider'],
            'provider_subject' => $profile['subject'],
            'provider_email' => $profile['email'],
            'provider_data' => $profile['raw'] ?? [],
        ]);

        return $pendingUser;
    }

    private function generateSlug(string $email): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]+/', '-', explode('@', $email)[0]));
        $base = trim($base, '-');
        $suffix = '';
        $counter = 1;

        while (Organization::withoutGlobalScope(OrganizationScope::class)->where('slug', $base.$suffix)->exists()) {
            $suffix = '-'.$counter;
            $counter++;
        }

        return $base.$suffix;
    }
}
