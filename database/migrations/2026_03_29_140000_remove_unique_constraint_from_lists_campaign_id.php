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
        // Drop foreign key first
        Schema::table('auto_dialer_lists', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
        });

        // Drop the unique constraint to allow multiple lists per campaign
        Schema::table('auto_dialer_lists', function (Blueprint $table) {
            $table->dropUnique('auto_dialer_lists_campaign_id_unique');
        });

        // Re-add foreign key without unique constraint
        Schema::table('auto_dialer_lists', function (Blueprint $table) {
            $table->foreign('campaign_id')
                ->references('id')
                ->on('auto_dialer_campaigns')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign key first
        Schema::table('auto_dialer_lists', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
        });

        // Re-add the unique constraint
        Schema::table('auto_dialer_lists', function (Blueprint $table) {
            $table->unique('campaign_id');
        });

        // Re-add foreign key with unique constraint
        Schema::table('auto_dialer_lists', function (Blueprint $table) {
            $table->foreign('campaign_id')
                ->references('id')
                ->on('auto_dialer_campaigns')
                ->onDelete('cascade');
        });
    }
};
