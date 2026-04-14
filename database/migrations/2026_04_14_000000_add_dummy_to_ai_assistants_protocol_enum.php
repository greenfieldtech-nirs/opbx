<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ai_assistants MODIFY COLUMN protocol ENUM('sip', 'websocket', 'dummy') NOT NULL");
        } else {
            // SQLite: drop index, drop column, add column, recreate index
            Schema::table('ai_assistants', function (Blueprint $table) {
                $table->dropIndex(['organization_id', 'protocol']);
            });

            DB::statement('ALTER TABLE ai_assistants DROP COLUMN protocol');

            Schema::table('ai_assistants', function (Blueprint $table) {
                $table->enum('protocol', ['sip', 'websocket', 'dummy'])->after('provider');
                $table->index(['organization_id', 'protocol']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ai_assistants MODIFY COLUMN protocol ENUM('sip', 'websocket') NOT NULL");
        } else {
            Schema::table('ai_assistants', function (Blueprint $table) {
                $table->dropIndex(['organization_id', 'protocol']);
            });

            DB::statement('ALTER TABLE ai_assistants DROP COLUMN protocol');

            Schema::table('ai_assistants', function (Blueprint $table) {
                $table->enum('protocol', ['sip', 'websocket'])->after('provider');
                $table->index(['organization_id', 'protocol']);
            });
        }
    }
};
