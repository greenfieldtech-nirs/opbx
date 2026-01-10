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
        // Note: SQLite treats ENUMs as TEXT, so no schema change is needed
        // The application will now accept 'internal' as a valid direction value
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove any 'internal' direction values
        \DB::table('session_updates')->where('direction', 'internal')->update(['direction' => 'outgoing']);
    }
};
