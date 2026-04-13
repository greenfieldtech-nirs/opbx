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
        Schema::table('ivr_menus', function (Blueprint $table) {
            $table->foreignId('recording_id')->nullable()->after('audio_file_path')->constrained('recordings')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ivr_menus', function (Blueprint $table) {
            $table->dropForeign(['recording_id']);
            $table->dropColumn('recording_id');
        });
    }
};
