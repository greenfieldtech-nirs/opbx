<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The AI Real Time Monitor reads each assistant's newest attempt ("last call") on every poll;
     * this index lets it read that row instead of scanning the retention window.
     */
    public function up(): void
    {
        Schema::table('ai_call_attempts', function (Blueprint $table) {
            $table->index(['ai_assistant_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_call_attempts', function (Blueprint $table) {
            // The foreign key needs an index on ai_assistant_id; restore the plain one first.
            $table->index('ai_assistant_id');
            $table->dropIndex(['ai_assistant_id', 'started_at']);
        });
    }
};
