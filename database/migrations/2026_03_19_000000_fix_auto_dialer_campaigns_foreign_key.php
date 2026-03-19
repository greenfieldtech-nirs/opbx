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
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['routing_destination_id']);

            // Remove the foreign key constraint but keep the column
            // This allows routing_destination_id to reference either ai_assistants OR ai_load_balancers
            // depending on routing_destination_type
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auto_dialer_campaigns', function (Blueprint $table) {
            // Re-add the foreign key constraint
            $table->foreign('routing_destination_id')
                ->references('id')
                ->on('ai_assistants')
                ->nullOnDelete();
        });
    }
};
