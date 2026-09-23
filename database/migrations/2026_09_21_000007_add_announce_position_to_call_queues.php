<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add periodic position-announcement options to call queues.
     */
    public function up(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->boolean('announce_position')->default(false)->after('wrap_up_seconds');
            $table->unsignedSmallInteger('announce_position_timeout')->default(60)->after('announce_position');
            $table->string('announce_position_language', 20)->nullable()->after('announce_position_timeout');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->dropColumn(['announce_position', 'announce_position_timeout', 'announce_position_language']);
        });
    }
};
