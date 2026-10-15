<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_dialer_destinations', function (Blueprint $table) {
            if (! Schema::hasColumn('auto_dialer_destinations', 'consecutive_initiate_failures')) {
                $table->unsignedSmallInteger('consecutive_initiate_failures')->default(0)->after('dial_attempts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('auto_dialer_destinations', function (Blueprint $table) {
            if (Schema::hasColumn('auto_dialer_destinations', 'consecutive_initiate_failures')) {
                $table->dropColumn('consecutive_initiate_failures');
            }
        });
    }
};
