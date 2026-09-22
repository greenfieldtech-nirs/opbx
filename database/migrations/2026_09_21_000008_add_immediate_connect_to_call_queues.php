<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the proactive connect toggle to call queues.
     */
    public function up(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->boolean('immediate_connect')->default(false)->after('announce_position_language');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->dropColumn('immediate_connect');
        });
    }
};
