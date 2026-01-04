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
        // Modify the direction enum to include 'internal'
        \DB::statement("ALTER TABLE session_updates MODIFY COLUMN direction ENUM('incoming', 'outgoing', 'internal')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert the direction enum to only include 'incoming' and 'outgoing'
        \DB::statement("ALTER TABLE session_updates MODIFY COLUMN direction ENUM('incoming', 'outgoing')");
    }
};
