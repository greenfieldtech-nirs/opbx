<?php

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
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: Need to drop index first, then recreate the column
            Schema::table('extensions', function (Blueprint $table) {
                $table->dropIndex(['organization_id', 'type']); // Drop composite index
            });

            Schema::table('extensions', function (Blueprint $table) {
                $table->string('type_temp')->default('user')->after('type');
            });

            DB::table('extensions')->update([
                'type_temp' => DB::raw('type')
            ]);

            Schema::table('extensions', function (Blueprint $table) {
                $table->dropColumn('type');
            });

            Schema::table('extensions', function (Blueprint $table) {
                $table->enum('type', [
                    'user',
                    'conference',
                    'ring_group',
                    'ivr',
                    'ai_assistant',
                    'forward'
                ])->default('user')->after('user_id');
            });

            DB::table('extensions')->update([
                'type' => DB::raw('type_temp')
            ]);

            Schema::table('extensions', function (Blueprint $table) {
                $table->dropColumn('type_temp');
            });

            // Recreate the index
            Schema::table('extensions', function (Blueprint $table) {
                $table->index(['organization_id', 'type']);
            });
        } else {
            // MySQL/PostgreSQL: Can use ALTER COLUMN
            DB::statement("ALTER TABLE extensions MODIFY COLUMN type ENUM('user','conference','ring_group','ivr','ai_assistant','forward') NOT NULL DEFAULT 'user'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: Need to drop index first, then recreate the column
            Schema::table('extensions', function (Blueprint $table) {
                $table->dropIndex(['organization_id', 'type']); // Drop composite index
            });

            Schema::table('extensions', function (Blueprint $table) {
                $table->string('type_temp')->default('user')->after('type');
            });

            DB::table('extensions')->update([
                'type_temp' => DB::raw('type')
            ]);

            Schema::table('extensions', function (Blueprint $table) {
                $table->dropColumn('type');
            });

            Schema::table('extensions', function (Blueprint $table) {
                $table->enum('type', [
                    'user',
                    'virtual',
                    'conference'
                ])->default('user')->after('user_id');
            });

            DB::table('extensions')->update([
                'type' => DB::raw('type_temp')
            ]);

            Schema::table('extensions', function (Blueprint $table) {
                $table->dropColumn('type_temp');
            });

            // Recreate the index
            Schema::table('extensions', function (Blueprint $table) {
                $table->index(['organization_id', 'type']);
            });
        } else {
            // MySQL/PostgreSQL: Can use ALTER COLUMN
            // Note: This will fail if there are extensions with ring_group, ivr, ai_assistant, or forward types
            DB::statement("ALTER TABLE extensions MODIFY COLUMN type ENUM('user','virtual','conference') NOT NULL DEFAULT 'user'");
        }
    }
};
