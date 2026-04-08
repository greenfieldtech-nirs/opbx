<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Add pause_reason and resume_at fields to auto_dialer_campaigns
 *
 * These fields track why a campaign was paused and when it can be resumed.
 * This is especially important for rate-limited campaigns (HTTP 429) that
 * need a cooldown period before resuming.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            $table->string('pause_reason')
                ->nullable()
                ->after('status')
                ->comment('Reason for pause: cloudonix_rate_limit, manual, schedule, etc.');

            $table->timestamp('resume_at')
                ->nullable()
                ->after('pause_reason')
                ->comment('When rate-limited campaign can resume (300s cooldown)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            $table->dropColumn(['pause_reason', 'resume_at']);
        });
    }
};
