<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiKey;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiKeyService
{
    public const PREFIX = 'opbxk_';

    /**
     * Create an API key with permissions. Returns [ApiKey, plaintextKey].
     * The plaintext is returned exactly once and never stored.
     *
     * @param  array<int, array{resource: string, level: string}>  $permissions
     * @return array{0: ApiKey, 1: string}
     */
    public function create(int $organizationId, string $name, array $permissions, ?int $createdBy): array
    {
        $plaintext = self::PREFIX.Str::random(40);

        return OrganizationScope::bypass(function () use ($organizationId, $name, $plaintext, $permissions, $createdBy) {
            return DB::transaction(function () use ($organizationId, $name, $plaintext, $permissions, $createdBy) {
                $apiKey = ApiKey::create([
                    'organization_id' => $organizationId,
                    'name' => $name,
                    'token' => hash('sha256', $plaintext),
                    'created_by' => $createdBy,
                ]);

                foreach ($permissions as $permission) {
                    $apiKey->permissions()->create([
                        'resource' => $permission['resource'],
                        'level' => $permission['level'],
                    ]);
                }

                return [$apiKey->load('permissions'), $plaintext];
            });
        });
    }

    /**
     * Atomically replace an API key's permission set. The old permissions and
     * the new ones are swapped inside a single transaction so a mid-write failure
     * can never leave the key with a partial (over- or under-scoped) permission
     * set. Returns the key with its fresh permissions loaded.
     *
     * @param  array<int, array{resource: string, level: string}>  $permissions
     */
    public function replacePermissions(ApiKey $apiKey, array $permissions): ApiKey
    {
        return OrganizationScope::bypass(function () use ($apiKey, $permissions) {
            return DB::transaction(function () use ($apiKey, $permissions) {
                $apiKey->permissions()->delete();

                foreach ($permissions as $permission) {
                    $apiKey->permissions()->create([
                        'resource' => $permission['resource'],
                        'level' => $permission['level'],
                    ]);
                }

                return $apiKey->load('permissions');
            });
        });
    }

    /**
     * Resolve a plaintext bearer token to an active (non-revoked) ApiKey, or null.
     */
    public function resolve(string $plaintext): ?ApiKey
    {
        if (! str_starts_with($plaintext, self::PREFIX)) {
            return null;
        }

        return OrganizationScope::bypass(
            fn () => ApiKey::with('permissions')
                ->where('token', hash('sha256', $plaintext))
                ->whereNull('revoked_at')
                ->first()
        );
    }
}
