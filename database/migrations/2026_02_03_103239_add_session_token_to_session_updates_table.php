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
        Schema::table('session_updates', function (Blueprint $table) {
            $table->string('session_token', 64)->nullable()->after('session_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('session_updates', function (Blueprint $table) {
            $table->dropColumn('session_token');
        });
    }
};
