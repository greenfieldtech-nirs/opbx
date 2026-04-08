<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add new columns first
        Schema::table('auto_dialer_lists', function (Blueprint $table) {
            // Add description column
            $table->text('description')->nullable()->after('name');

            // Versioning fields
            $table->unsignedInteger('version_number')->default(1)->after('description');
            $table->unsignedBigInteger('parent_list_id')->nullable()->after('version_number');
            $table->boolean('is_latest_version')->default(true)->after('parent_list_id');

            // Usage tracking
            $table->unsignedBigInteger('used_by_campaign_id')->nullable()->after('status');
            $table->timestamp('used_at')->nullable()->after('used_by_campaign_id');

            // Validation errors storage
            $table->json('validation_errors')->nullable()->after('invalid_rows');

            // Archival
            $table->timestamp('archived_at')->nullable()->after('updated_at');

            // New indexes
            $table->index(['organization_id', 'status'], 'idx_org_status');
            $table->index(['parent_list_id', 'version_number'], 'idx_version');
            $table->index(['organization_id', 'is_latest_version'], 'idx_latest');
        });

        // Modify status enum to include new values (MySQL only - SQLite doesn't support MODIFY)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE auto_dialer_lists MODIFY COLUMN status ENUM('draft', 'pending', 'processing', 'ready', 'failed', 'in_use', 'used', 'archived') DEFAULT 'draft'");
        }

        // Add foreign key for parent_list_id
        Schema::table('auto_dialer_lists', function (Blueprint $table) {
            $table->foreign('parent_list_id')->references('id')->on('auto_dialer_lists')->onDelete('set null');
            $table->foreign('used_by_campaign_id')->references('id')->on('auto_dialer_campaigns')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auto_dialer_lists', function (Blueprint $table) {
            $table->dropForeign(['parent_list_id']);
            $table->dropForeign(['used_by_campaign_id']);

            $table->dropIndex('idx_org_status');
            $table->dropIndex('idx_version');
            $table->dropIndex('idx_latest');

            $table->dropColumn([
                'description',
                'version_number',
                'parent_list_id',
                'is_latest_version',
                'used_by_campaign_id',
                'used_at',
                'validation_errors',
                'archived_at',
            ]);
        });

        // Restore original enum (MySQL only - SQLite doesn't support MODIFY)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE auto_dialer_lists MODIFY COLUMN status ENUM('pending', 'processing', 'ready', 'failed') DEFAULT 'pending'");
        }
    }
};
