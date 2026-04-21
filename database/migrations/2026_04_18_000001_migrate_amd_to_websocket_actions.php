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
        // Step 1: Add new columns first (if they don't already exist)
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            // Add new WebSocket-based AMD action columns
            if (! Schema::hasColumn('auto_dialer_campaigns', 'action_voicemail')) {
                $table->string('action_voicemail')->nullable()->after('record_calls')
                    ->comment('HANGUP, CONTINUE, or CXML URL');
            }
            if (! Schema::hasColumn('auto_dialer_campaigns', 'action_human')) {
                $table->string('action_human')->nullable()->after('action_voicemail')
                    ->comment('HANGUP, CONTINUE, or CXML URL');
            }
            if (! Schema::hasColumn('auto_dialer_campaigns', 'action_unknown')) {
                $table->string('action_unknown')->nullable()->after('action_human')
                    ->comment('HANGUP, CONTINUE, or CXML URL');
            }
            if (! Schema::hasColumn('auto_dialer_campaigns', 'retry_on_voicemail')) {
                $table->boolean('retry_on_voicemail')->default(false)->after('action_unknown');
            }

            // Add voicemail statistics counter
            if (! Schema::hasColumn('auto_dialer_campaigns', 'voicemail_calls')) {
                $table->unsignedInteger('voicemail_calls')->default(0)->after('failed_calls');
            }
        });

        // Step 2: Migrate existing data while old columns still exist
        if (Schema::hasColumn('auto_dialer_campaigns', 'amd_enabled')) {
            DB::table('auto_dialer_campaigns')
                ->update([
                    'action_voicemail' => DB::raw("CASE WHEN amd_enabled = 1 THEN 'HANGUP' ELSE NULL END"),
                    'action_human' => DB::raw("CASE WHEN amd_enabled = 1 THEN 'CONTINUE' ELSE NULL END"),
                    'action_unknown' => DB::raw("CASE WHEN amd_enabled = 1 THEN 'HANGUP' ELSE NULL END"),
                ]);
        }

        // Step 3: Drop old columns after data migration
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('auto_dialer_campaigns', 'amd_enabled')) {
                $columnsToDrop[] = 'amd_enabled';
            }
            if (Schema::hasColumn('auto_dialer_campaigns', 'amd_mode')) {
                $columnsToDrop[] = 'amd_mode';
            }
            if (Schema::hasColumn('auto_dialer_campaigns', 'amd_timeout')) {
                $columnsToDrop[] = 'amd_timeout';
            }
            if (Schema::hasColumn('auto_dialer_campaigns', 'amd_speech_threshold')) {
                $columnsToDrop[] = 'amd_speech_threshold';
            }
            if (Schema::hasColumn('auto_dialer_campaigns', 'amd_speech_end_threshold')) {
                $columnsToDrop[] = 'amd_speech_end_threshold';
            }
            if (Schema::hasColumn('auto_dialer_campaigns', 'amd_silence_timeout')) {
                $columnsToDrop[] = 'amd_silence_timeout';
            }

            if (count($columnsToDrop) > 0) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'action_voicemail',
                'action_human',
                'action_unknown',
                'retry_on_voicemail',
                'voicemail_calls',
            ]);

            // Restore old columns (data is lost)
            $table->boolean('amd_enabled')->default(false);
            $table->enum('amd_mode', ['Enabled', 'DetectMessageEnd'])->nullable();
            $table->unsignedSmallInteger('amd_timeout')->default(30);
            $table->unsignedSmallInteger('amd_speech_threshold')->default(1500);
            $table->unsignedSmallInteger('amd_speech_end_threshold')->default(2500);
            $table->unsignedSmallInteger('amd_silence_timeout')->default(3500);
        });
    }
};
