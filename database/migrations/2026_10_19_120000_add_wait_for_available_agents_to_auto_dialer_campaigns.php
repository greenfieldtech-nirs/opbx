<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ring group campaigns can hold dialing while every agent of the ring group is on a call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_dialer_campaigns', function (Blueprint $table): void {
            $table->boolean('wait_for_available_agents')->default(false)->after('routing_destination_id');
        });
    }

    public function down(): void
    {
        Schema::table('auto_dialer_campaigns', function (Blueprint $table): void {
            $table->dropColumn('wait_for_available_agents');
        });
    }
};
