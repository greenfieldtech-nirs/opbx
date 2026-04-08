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
        Schema::table('auto_dialer_destinations', function (Blueprint $table) {
            if (! Schema::hasColumn('auto_dialer_destinations', 'next_retry_at')) {
                $table->timestamp('next_retry_at')->nullable()->after('last_dialed_at');

                // Add index for retry queries
                $table->index(['status', 'next_retry_at'], 'add_retry_at_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auto_dialer_destinations', function (Blueprint $table) {
            if (Schema::hasColumn('auto_dialer_destinations', 'next_retry_at')) {
                $table->dropIndex('add_retry_at_idx');
                $table->dropColumn('next_retry_at');
            }
        });
    }
};
