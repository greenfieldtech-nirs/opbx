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
            // SQLite: Need to recreate the column
            Schema::table('ring_groups', function (Blueprint $table) {
                $table->string('fallback_action_temp')->default('extension')->after('fallback_action');
            });

            DB::table('ring_groups')->update([
                'fallback_action_temp' => DB::raw('fallback_action')
            ]);

            Schema::table('ring_groups', function (Blueprint $table) {
                $table->dropColumn('fallback_action');
            });

            Schema::table('ring_groups', function (Blueprint $table) {
                $table->enum('fallback_action', [
                    'extension',
                    'hangup'
                ])->default('extension')->after('strategy');
            });

            DB::table('ring_groups')->update([
                'fallback_action' => DB::raw('fallback_action_temp')
            ]);

            Schema::table('ring_groups', function (Blueprint $table) {
                $table->dropColumn('fallback_action_temp');
            });
        } else {
            // MySQL/PostgreSQL: Can use ALTER COLUMN
            DB::statement("ALTER TABLE ring_groups MODIFY COLUMN fallback_action ENUM('extension', 'hangup') DEFAULT 'extension'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: Need to recreate the column
            Schema::table('ring_groups', function (Blueprint $table) {
                $table->text('fallback_action_temp')->nullable()->after('fallback_action');
            });

            DB::table('ring_groups')->update([
                'fallback_action_temp' => DB::raw('fallback_action')
            ]);

            Schema::table('ring_groups', function (Blueprint $table) {
                $table->dropColumn('fallback_action');
            });

            Schema::table('ring_groups', function (Blueprint $table) {
                $table->json('fallback_action')->nullable()->after('strategy');
            });

            DB::table('ring_groups')->update([
                'fallback_action' => DB::raw('fallback_action_temp')
            ]);

            Schema::table('ring_groups', function (Blueprint $table) {
                $table->dropColumn('fallback_action_temp');
            });
        } else {
            // MySQL/PostgreSQL: Can use ALTER COLUMN
            // Revert back to JSON (though this is unlikely to be needed)
            DB::statement("ALTER TABLE ring_groups MODIFY COLUMN fallback_action JSON NULL");
        }
    }
};
