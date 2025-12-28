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
        Schema::table('extensions', function (Blueprint $table) {
            // Cloudonix Subscriber Integration Fields
            $table->string('cloudonix_subscriber_id', 50)->nullable()->after('password')->index();
            $table->string('cloudonix_uuid', 100)->nullable()->after('cloudonix_subscriber_id');
            $table->boolean('cloudonix_synced')->default(false)->after('cloudonix_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropIndex(['cloudonix_subscriber_id']);
            $table->dropColumn(['cloudonix_subscriber_id', 'cloudonix_uuid', 'cloudonix_synced']);
        });
    }
};
