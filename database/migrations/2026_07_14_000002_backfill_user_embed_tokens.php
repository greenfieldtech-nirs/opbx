<?php

declare(strict_types=1);

use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\EmbedTokenService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $service = app(EmbedTokenService::class);

        OrganizationScope::bypass(function () use ($service) {
            User::query()
                ->whereDoesntHave('embedToken')
                ->chunkById(200, function ($users) use ($service) {
                    foreach ($users as $user) {
                        $service->generateFor($user);
                    }
                });
        });
    }

    public function down(): void
    {
        // Tokens are dropped with the table; no per-row rollback.
    }
};
