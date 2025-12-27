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
            // Rename sip_config to configuration to match model expectations
            $table->renameColumn('sip_config', 'configuration');

            // Also drop call_forwarding as it's not used in the model
            if (Schema::hasColumn('extensions', 'call_forwarding')) {
                $table->dropColumn('call_forwarding');
            }

            // Drop friendly_name as it's not in the model
            if (Schema::hasColumn('extensions', 'friendly_name')) {
                $table->dropColumn('friendly_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            // Restore original column name
            $table->renameColumn('configuration', 'sip_config');

            // Restore dropped columns
            $table->json('call_forwarding')->nullable();
            $table->string('friendly_name', 255)->nullable();
        });
    }
};
