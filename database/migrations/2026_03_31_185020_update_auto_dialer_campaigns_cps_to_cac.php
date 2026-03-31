<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Replace Calls Per Second (CPS) with Concurrent Active Calls (CAC)
 *
 * This migration changes the dialing rate configuration from a CPS-based model
 * to a CAC-based model. Instead of limiting how many calls are initiated per
 * second, we now limit how many calls can be active (ringing or connected)
 * at the same time.
 *
 * Valid CAC values: 2, 3, 4, 6, 10, 15, 20
 * API request interval is calculated as: 60 / CAC seconds
 *
 * Example:
 *   CAC = 5  → API call every 12 seconds, max 5 concurrent calls
 *   CAC = 10 → API call every 6 seconds, max 10 concurrent calls
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Steps:
     * 1. Add new 'concurrent_active_calls' column with default value of 5
     * 2. Migrate existing data: map old CPS values to appropriate CAC values
     * 3. Remove old 'calls_per_second' column
     */
    public function up(): void
    {
        // Step 1: Add the new CAC column
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            $table->unsignedTinyInteger('concurrent_active_calls')
                ->default(5)  // Default to middle value
                ->after('max_dial_attempts')
                ->comment('Max concurrent active calls (2,3,4,6,10,15,20)');
        });

        // Step 2: Migrate existing CPS values to CAC values
        // Mapping logic: CPS 1-2 → CAC 2, CPS 3-4 → CAC 4, CPS 5 → CAC 6
        DB::table('auto_dialer_campaigns')->cursor()->each(function ($campaign) {
            $cac = $this->mapCpsToCac($campaign->calls_per_second);
            DB::table('auto_dialer_campaigns')
                ->where('id', $campaign->id)
                ->update(['concurrent_active_calls' => $cac]);
        });

        // Step 3: Remove the old CPS column
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            $table->dropColumn('calls_per_second');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Steps:
     * 1. Re-add 'calls_per_second' column
     * 2. Migrate CAC values back to approximate CPS values
     * 3. Remove 'concurrent_active_calls' column
     */
    public function down(): void
    {
        // Step 1: Add back the CPS column
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            $table->unsignedTinyInteger('calls_per_second')
                ->default(1)
                ->after('max_dial_attempts')
                ->comment('Deprecated: Use concurrent_active_calls');
        });

        // Step 2: Migrate CAC values back to CPS
        DB::table('auto_dialer_campaigns')->cursor()->each(function ($campaign) {
            $cps = $this->mapCacToCps($campaign->concurrent_active_calls);
            DB::table('auto_dialer_campaigns')
                ->where('id', $campaign->id)
                ->update(['calls_per_second' => $cps]);
        });

        // Step 3: Remove CAC column
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            $table->dropColumn('concurrent_active_calls');
        });
    }

    /**
     * Map old CPS values to new CAC values.
     *
     * The mapping is designed to maintain similar dialing behavior while
     * switching from rate-based to concurrency-based limiting.
     *
     * CPS 1-2: Low rate → CAC 2 (conservative)
     * CPS 3-4: Medium rate → CAC 4 (moderate)
     * CPS 5: High rate → CAC 6 (aggressive)
     *
     * @param  int|null  $cps  Old calls_per_second value
     * @return int New concurrent_active_calls value
     */
    private function mapCpsToCac(?int $cps): int
    {
        return match (true) {
            $cps === null => 5,        // Default for null values
            $cps <= 2 => 2,            // Low CPS → conservative CAC
            $cps <= 4 => 4,            // Medium CPS → moderate CAC
            default => 6,              // High CPS → aggressive CAC
        };
    }

    /**
     * Map CAC values back to approximate CPS values for rollback.
     *
     * This is an approximate mapping since CAC and CPS have different
     * semantics. We use the middle of the original CPS range.
     *
     * @param  int  $cac  Concurrent active calls value
     * @return int Approximate CPS value
     */
    private function mapCacToCps(int $cac): int
    {
        return match (true) {
            $cac <= 2 => 1,            // Conservative CAC → low CPS
            $cac <= 4 => 3,            // Moderate CAC → medium CPS
            default => 5,              // Aggressive CAC → high CPS
        };
    }
};
