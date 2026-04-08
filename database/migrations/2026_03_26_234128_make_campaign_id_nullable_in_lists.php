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
        Schema::table('auto_dialer_lists', function (Blueprint $table) {
            // Drop existing foreign key
            $table->dropForeign(['campaign_id']);
        });

        // Modify column to be nullable
        Schema::table('auto_dialer_lists', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->change();
        });

        // Re-add foreign key with set null
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
        Schema::table('auto_dialer_lists', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
        });

        Schema::table('auto_dialer_lists', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable(false)->change();
        });

        Schema::table('auto_dialer_lists', function (Blueprint $table) {
            $table->foreign('campaign_id')
                ->references('id')
                ->on('auto_dialer_campaigns')
                ->onDelete('cascade');
        });
    }
};
