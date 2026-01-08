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
        Schema::table('ivr_menus', function (Blueprint $table) {
            // Check if column doesn't exist before adding
            if (!Schema::hasColumn('ivr_menus', 'tts_voice')) {
                $table->string('tts_voice', 50)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ivr_menus', function (Blueprint $table) {
            $table->dropColumn('tts_voice');
        });
    }
};
