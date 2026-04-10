<?php

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
        // 1. Create pivot table for Caller ID pool (if not exists)
        if (! Schema::hasTable('auto_dialer_campaign_caller_ids')) {
            Schema::create('auto_dialer_campaign_caller_ids', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_id')
                    ->constrained('auto_dialer_campaigns')
                    ->onDelete('cascade');
                $table->foreignId('did_number_id')
                    ->constrained('did_numbers')
                    ->onDelete('cascade');
                $table->unsignedInteger('weight')->default(1);
                $table->timestamps();

                // Prevent duplicate assignments
                $table->unique(['campaign_id', 'did_number_id']);
            });
        }

        // 2. Create statistics table (if not exists)
        if (! Schema::hasTable('auto_dialer_caller_id_stats')) {
            Schema::create('auto_dialer_caller_id_stats', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_id')
                    ->constrained('auto_dialer_campaigns')
                    ->onDelete('cascade');
                $table->foreignId('did_number_id')
                    ->constrained('did_numbers')
                    ->onDelete('cascade');
                $table->unsignedInteger('total_calls')->default(0);
                $table->unsignedInteger('completed_calls')->default(0);
                $table->unsignedInteger('failed_calls')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                // One stats record per campaign-DID pair
                $table->unique(['campaign_id', 'did_number_id']);
            });
        }

        // 3. Add columns to auto_dialer_campaigns (if not exists)
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('auto_dialer_campaigns', 'caller_id_strategy')) {
                $table->string('caller_id_strategy', 20)
                    ->default('round_robin')
                    ->after('caller_id')
                    ->comment('round_robin, random, least_recently_used');
            }
            if (! Schema::hasColumn('auto_dialer_campaigns', 'caller_id_pool_enabled')) {
                $table->boolean('caller_id_pool_enabled')
                    ->default(false)
                    ->after('caller_id_strategy');
            }
        });

        // 4. Add columns to auto_dialer_call_sessions
        Schema::table('auto_dialer_call_sessions', function (Blueprint $table) {
            // First add caller_id if it doesn't exist (for storing the phone number used)
            if (! Schema::hasColumn('auto_dialer_call_sessions', 'caller_id')) {
                $table->string('caller_id', 50)->nullable()->after('destination_id');
            }

            // Add caller_did_id for tracking which DID was used
            if (! Schema::hasColumn('auto_dialer_call_sessions', 'caller_did_id')) {
                $table->foreignId('caller_did_id')
                    ->nullable()
                    ->after('caller_id')
                    ->constrained('did_numbers')
                    ->onDelete('set null');
            }
        });

        // 5. Data migration: Migrate existing campaigns with caller_id
        $this->migrateExistingCampaigns();
    }

    /**
     * Migrate existing campaigns to new pool format.
     */
    private function migrateExistingCampaigns(): void
    {
        // Get campaigns with a caller_id that matches a DID
        $campaigns = DB::table('auto_dialer_campaigns')
            ->whereNotNull('caller_id')
            ->where('caller_id', '!=', '')
            ->get();

        foreach ($campaigns as $campaign) {
            // Find matching DID by phone number
            $did = DB::table('did_numbers')
                ->where('phone_number', $campaign->caller_id)
                ->where('organization_id', $campaign->organization_id)
                ->first();

            if ($did) {
                // Create pool entry
                DB::table('auto_dialer_campaign_caller_ids')->insert([
                    'campaign_id' => $campaign->id,
                    'did_number_id' => $did->id,
                    'weight' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create initial stats record
                DB::table('auto_dialer_caller_id_stats')->insert([
                    'campaign_id' => $campaign->id,
                    'did_number_id' => $did->id,
                    'total_calls' => 0,
                    'completed_calls' => 0,
                    'failed_calls' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Enable pool mode
                DB::table('auto_dialer_campaigns')
                    ->where('id', $campaign->id)
                    ->update(['caller_id_pool_enabled' => true]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign key and column from auto_dialer_call_sessions
        Schema::table('auto_dialer_call_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('auto_dialer_call_sessions', 'caller_did_id')) {
                $table->dropForeign(['caller_did_id']);
                $table->dropColumn('caller_did_id');
            }
            // Note: We don't drop caller_id here as it might have been added by this migration
            // or might have existed before. Manual cleanup may be needed on rollback.
        });

        // Drop columns from auto_dialer_campaigns
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            $table->dropColumn(['caller_id_strategy', 'caller_id_pool_enabled']);
        });

        // Drop statistics table
        Schema::dropIfExists('auto_dialer_caller_id_stats');

        // Drop pivot table
        Schema::dropIfExists('auto_dialer_campaign_caller_ids');
    }
};
