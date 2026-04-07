<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            $table->unsignedTinyInteger('calls_per_second')
                ->default(1)
                ->after('concurrent_active_calls');
        });
    }

    public function down(): void
    {
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            $table->dropColumn('calls_per_second');
        });
    }
};
