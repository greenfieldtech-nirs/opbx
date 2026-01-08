<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop foreign key constraints first
        Schema::table('ivr_menus', function (Blueprint $table) {
            $foreignKeys = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_NAME = 'ivr_menus' AND CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL");

            foreach ($foreignKeys as $fk) {
                try {
                    $table->dropForeign($fk->CONSTRAINT_NAME);
                } catch (\Exception $e) {
                    // Ignore if foreign key doesn't exist
                }
            }
        });

        // Drop old columns
        Schema::table('ivr_menus', function (Blueprint $table) {
            $columnsToDrop = [
                'welcome_prompt_type',
                'welcome_prompt_text',
                'welcome_prompt_audio_url',
                'timeout_seconds',
                'max_attempts',
                'failover_extension_id',
                'failover_ring_group_id',
                'failover_voicemail_enabled',
                'deleted_at',
            ];

            foreach ($columnsToDrop as $column) {
                if (Schema::hasColumn('ivr_menus', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // Add new columns
        Schema::table('ivr_menus', function (Blueprint $table) {
            if (!Schema::hasColumn('ivr_menus', 'audio_file_path')) {
                $table->string('audio_file_path', 500)->nullable()->after('description');
            }
            if (!Schema::hasColumn('ivr_menus', 'tts_text')) {
                $table->text('tts_text')->nullable()->after('audio_file_path');
            }
            // tts_voice already added by previous migration
            if (!Schema::hasColumn('ivr_menus', 'max_turns')) {
                $table->tinyInteger('max_turns')->unsigned()->default(3)->after('tts_voice');
            }
            if (!Schema::hasColumn('ivr_menus', 'failover_destination_type')) {
                $table->enum('failover_destination_type', ['extension', 'ring_group', 'conference_room', 'ivr_menu', 'hangup'])
                    ->default('hangup')
                    ->after('max_turns');
            }
            if (!Schema::hasColumn('ivr_menus', 'failover_destination_id')) {
                $table->unsignedBigInteger('failover_destination_id')->nullable()->after('failover_destination_type');
            }
            if (!Schema::hasColumn('ivr_menus', 'status')) {
                $table->enum('status', ['active', 'inactive'])->default('active')->after('failover_destination_id');
            }
        });

        // Add indexes
        if (!DB::select("SHOW INDEX FROM ivr_menus WHERE Key_name = 'idx_ivr_menus_org_status'")) {
            DB::statement('CREATE INDEX idx_ivr_menus_org_status ON ivr_menus (organization_id, status)');
        }
        if (!DB::select("SHOW INDEX FROM ivr_menus WHERE Key_name = 'idx_ivr_menus_org_name'")) {
            DB::statement('CREATE INDEX idx_ivr_menus_org_name ON ivr_menus (organization_id, name)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove new columns
        Schema::table('ivr_menus', function (Blueprint $table) {
            $table->dropColumn([
                'audio_file_path',
                'tts_text',
                'max_turns',
                'failover_destination_type',
                'failover_destination_id',
                'status',
            ]);
        });

        // Add back old columns
        Schema::table('ivr_menus', function (Blueprint $table) {
            $table->enum('welcome_prompt_type', ['tts', 'audio'])->after('description');
            $table->text('welcome_prompt_text')->nullable()->after('welcome_prompt_type');
            $table->string('welcome_prompt_audio_url')->nullable()->after('welcome_prompt_text');
            $table->unsignedInteger('timeout_seconds')->after('welcome_prompt_audio_url');
            $table->unsignedInteger('max_attempts')->after('timeout_seconds');
            $table->unsignedBigInteger('failover_extension_id')->nullable()->after('max_attempts');
            $table->unsignedBigInteger('failover_ring_group_id')->nullable()->after('failover_extension_id');
            $table->boolean('failover_voicemail_enabled')->default(false)->after('failover_ring_group_id');
            $table->softDeletes();
        });

        // Drop indexes
        Schema::table('ivr_menus', function (Blueprint $table) {
            $table->dropIndex('idx_ivr_menus_org_status');
            $table->dropIndex('idx_ivr_menus_org_name');
        });
    }
};
