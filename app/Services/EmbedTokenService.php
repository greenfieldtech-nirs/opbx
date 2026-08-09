<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserEmbedToken;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Str;

final class EmbedTokenService
{
    public const PREFIX = 'opbxd_';

    /**
     * Create a token row for a user. Returns [model, plaintext] — plaintext shown once.
     *
     * @return array{0: UserEmbedToken, 1: string}
     */
    public function generateFor(User $user): array
    {
        $plaintext = self::PREFIX.Str::random(40);

        $model = OrganizationScope::bypass(fn () => UserEmbedToken::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'token' => hash('sha256', $plaintext),
            'icon_position' => 'bottom-right',
            'icon_background_color' => '#007acc',
        ]));

        return [$model, $plaintext];
    }

    /**
     * Rotate the token hash in place, preserving icon config.
     * Returns [model, plaintext].
     *
     * @return array{0: UserEmbedToken, 1: string}
     */
    public function regenerateFor(User $user): array
    {
        $model = OrganizationScope::bypass(
            fn () => UserEmbedToken::where('user_id', $user->id)->first()
        );

        if (! $model) {
            return $this->generateFor($user);
        }

        $plaintext = self::PREFIX.Str::random(40);
        $model->token = hash('sha256', $plaintext);
        OrganizationScope::bypass(fn () => $model->save());

        return [$model, $plaintext];
    }

    public function resolve(string $plaintext): ?UserEmbedToken
    {
        if (! str_starts_with($plaintext, self::PREFIX)) {
            return null;
        }

        return OrganizationScope::bypass(
            fn () => UserEmbedToken::where('token', hash('sha256', $plaintext))->first()
        );
    }
}
