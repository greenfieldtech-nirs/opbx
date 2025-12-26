<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update existing records that have default values
        DB::table('cloudonix_settings')
            ->where('no_answer_timeout', 30)
            ->update(['no_answer_timeout' => 60]);

        DB::table('cloudonix_settings')
            ->where('recording_format', 'wav')
            ->update(['recording_format' => 'mp3']);

        // Alter column defaults
        Schema::table('cloudonix_settings', function (Blueprint $table): void {
            $table->integer('no_answer_timeout')->default(60)->change();
            $table->enum('recording_format', ['wav', 'mp3'])->default('mp3')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to old defaults
        Schema::table('cloudonix_settings', function (Blueprint $table): void {
            $table->integer('no_answer_timeout')->default(30)->change();
            $table->enum('recording_format', ['wav', 'mp3'])->default('wav')->change();
        });

        // Update records back to old defaults
        DB::table('cloudonix_settings')
            ->where('no_answer_timeout', 60)
            ->update(['no_answer_timeout' => 30]);

        DB::table('cloudonix_settings')
            ->where('recording_format', 'mp3')
            ->update(['recording_format' => 'wav']);
    }
};
