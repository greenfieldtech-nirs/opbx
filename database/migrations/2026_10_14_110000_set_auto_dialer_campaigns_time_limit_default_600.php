<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A campaign with no time limit defined gets 600 seconds (was 3600). Existing rows keep their value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_dialer_campaigns', function (Blueprint $table): void {
            $table->unsignedMediumInteger('time_limit')->default(600)->change();
        });
    }

    public function down(): void
    {
        Schema::table('auto_dialer_campaigns', function (Blueprint $table): void {
            $table->unsignedMediumInteger('time_limit')->default(3600)->change();
        });
    }
};
