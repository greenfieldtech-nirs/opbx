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
        Schema::table('auto_dialer_destinations', function (Blueprint $table) {
            if (! Schema::hasColumn('auto_dialer_destinations', 'priority')) {
                $table->unsignedInteger('priority')->default(1)->after('dial_attempts')
                    ->comment('Priority for dialing order (1 is highest)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auto_dialer_destinations', function (Blueprint $table) {
            if (Schema::hasColumn('auto_dialer_destinations', 'priority')) {
                $table->dropColumn('priority');
            }
        });
    }
};
