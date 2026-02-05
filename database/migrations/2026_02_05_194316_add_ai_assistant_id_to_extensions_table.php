<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds ai_assistant_id foreign key to extensions table to link extensions
     * to AI Assistants for call handling.
     */
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->foreignId('ai_assistant_id')
                ->nullable()
                ->after('service_params')
                ->constrained('ai_assistants')
                ->onDelete('set null')
                ->comment('Reference to AI Assistant handling calls for this extension');

            $table->index('ai_assistant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropForeign(['ai_assistant_id']);
            $table->dropIndex(['ai_assistant_id']);
            $table->dropColumn('ai_assistant_id');
        });
    }
};
