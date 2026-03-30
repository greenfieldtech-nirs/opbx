<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('auto_dialer_call_sessions', function (Blueprint $table) {
            // Phone number that was dialed
            $table->string('phone_number', 50)->nullable()->after('destination_id');

            // Worker that initiated the call
            $table->string('worker_id', 255)->nullable()->after('phone_number');

            // Disposition from Cloudonix
            $table->string('disposition', 50)->nullable()->after('status');

            // Call duration
            $table->unsignedInteger('duration')->default(0)->after('disposition');

            // Billable seconds
            $table->unsignedInteger('billsec')->default(0)->after('duration');

            // Recording URL
            $table->text('recording_url')->nullable()->after('billsec');

            // Add index for worker tracking
            $table->index('worker_id', 'adcs_worker_id_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auto_dialer_call_sessions', function (Blueprint $table) {
            $table->dropIndex('adcs_worker_id_idx');
            $table->dropColumn('phone_number');
            $table->dropColumn('worker_id');
            $table->dropColumn('disposition');
            $table->dropColumn('duration');
            $table->dropColumn('billsec');
            $table->dropColumn('recording_url');
        });
    }
};
