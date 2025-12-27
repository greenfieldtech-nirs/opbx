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
     *
     * This migration updates the routing_type enum in the did_numbers table:
     * - Removes: 'ivr', 'voicemail'
     * - Adds: 'conference_room'
     * - Converts any existing 'ivr' or 'voicemail' records to 'extension'
     */
    public function up(): void
    {
        // First, migrate any existing 'ivr' or 'voicemail' records to 'extension'
        DB::table('did_numbers')
            ->whereIn('routing_type', ['ivr', 'voicemail'])
            ->update(['routing_type' => 'extension']);

        // Now modify the enum to remove old values and add new one
        // Note: SQLite doesn't support ALTER COLUMN, so we need to check the driver
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: Need to recreate the table
            Schema::table('did_numbers', function (Blueprint $table) {
                $table->string('routing_type_temp')->default('extension')->after('routing_type');
            });

            DB::table('did_numbers')->update([
                'routing_type_temp' => DB::raw('routing_type')
            ]);

            Schema::table('did_numbers', function (Blueprint $table) {
                $table->dropColumn('routing_type');
            });

            Schema::table('did_numbers', function (Blueprint $table) {
                $table->enum('routing_type', [
                    'extension',
                    'ring_group',
                    'business_hours',
                    'conference_room'
                ])->default('extension')->after('friendly_name');
            });

            DB::table('did_numbers')->update([
                'routing_type' => DB::raw('routing_type_temp')
            ]);

            Schema::table('did_numbers', function (Blueprint $table) {
                $table->dropColumn('routing_type_temp');
            });
        } else {
            // MySQL/PostgreSQL: Can use ALTER COLUMN
            DB::statement("ALTER TABLE did_numbers MODIFY COLUMN routing_type ENUM('extension', 'ring_group', 'business_hours', 'conference_room') DEFAULT 'extension'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: Need to recreate the table
            Schema::table('did_numbers', function (Blueprint $table) {
                $table->string('routing_type_temp')->default('extension')->after('routing_type');
            });

            DB::table('did_numbers')->update([
                'routing_type_temp' => DB::raw('routing_type')
            ]);

            Schema::table('did_numbers', function (Blueprint $table) {
                $table->dropColumn('routing_type');
            });

            Schema::table('did_numbers', function (Blueprint $table) {
                $table->enum('routing_type', [
                    'extension',
                    'ring_group',
                    'business_hours',
                    'ivr',
                    'voicemail'
                ])->default('extension')->after('friendly_name');
            });

            DB::table('did_numbers')->update([
                'routing_type' => DB::raw('routing_type_temp')
            ]);

            Schema::table('did_numbers', function (Blueprint $table) {
                $table->dropColumn('routing_type_temp');
            });
        } else {
            // MySQL/PostgreSQL: Can use ALTER COLUMN
            DB::statement("ALTER TABLE did_numbers MODIFY COLUMN routing_type ENUM('extension', 'ring_group', 'business_hours', 'ivr', 'voicemail') DEFAULT 'extension'");
        }
    }
};
