<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds impersonation context to personal access tokens.
 *
 * When a platform manager impersonates an organization, a dedicated token is
 * minted with this column set to the target organization's id. A non-null value
 * marks the token as an impersonation token whose requests are scoped to that
 * organization (see OrganizationScope + SetImpersonationContext middleware).
 *
 * Existing tokens are unaffected (column is nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->unsignedBigInteger('impersonated_organization_id')
                ->nullable()
                ->after('abilities')
                ->index('idx_pat_impersonated_organization_id');

            $table->foreign('impersonated_organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropForeign(['impersonated_organization_id']);
            $table->dropIndex('idx_pat_impersonated_organization_id');
            $table->dropColumn('impersonated_organization_id');
        });
    }
};
