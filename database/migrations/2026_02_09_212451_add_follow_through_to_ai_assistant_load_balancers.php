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
        Schema::table('ai_assistant_load_balancers', function (Blueprint $table) {
            $table->boolean('follow_through')->default(false)->after('strategy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_assistant_load_balancers', function (Blueprint $table) {
            $table->dropColumn('follow_through');
        });
    }
};
