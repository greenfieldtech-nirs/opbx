<?php

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
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            if (! Schema::hasColumn('auto_dialer_campaigns', 'concurrent_active_calls')) {
                $table->unsignedInteger('concurrent_active_calls')->default(0)->after('calls_per_second')
                    ->comment('Maximum number of concurrent active calls for this campaign');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            if (Schema::hasColumn('auto_dialer_campaigns', 'concurrent_active_calls')) {
                $table->dropColumn('concurrent_active_calls');
            }
        });
    }
};
