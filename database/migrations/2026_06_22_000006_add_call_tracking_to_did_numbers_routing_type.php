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
     * Adds 'call_tracking' to the did_numbers.routing_type enum.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('did_numbers', function (Blueprint $table) {
                $table->string('routing_type_temp')->default('extension')->after('routing_type');
            });

            DB::table('did_numbers')->update([
                'routing_type_temp' => DB::raw('routing_type'),
            ]);

            Schema::table('did_numbers', function (Blueprint $table) {
                $table->dropColumn('routing_type');
            });

            Schema::table('did_numbers', function (Blueprint $table) {
                $table->enum('routing_type', [
                    'extension',
                    'ring_group',
                    'business_hours',
                    'conference_room',
                    'ai_assistant',
                    'ai_load_balancer',
                    'ivr_menu',
                    'call_tracking',
                ])->default('extension')->after('friendly_name');
            });

            DB::table('did_numbers')->update([
                'routing_type' => DB::raw('routing_type_temp'),
            ]);

            Schema::table('did_numbers', function (Blueprint $table) {
                $table->dropColumn('routing_type_temp');
            });
        } else {
            DB::statement("ALTER TABLE did_numbers MODIFY COLUMN routing_type ENUM('extension', 'ring_group', 'business_hours', 'conference_room', 'ai_assistant', 'ai_load_balancer', 'ivr_menu', 'call_tracking') DEFAULT 'extension'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('did_numbers')
            ->where('routing_type', 'call_tracking')
            ->update(['routing_type' => 'extension']);

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('did_numbers', function (Blueprint $table) {
                $table->string('routing_type_temp')->default('extension')->after('routing_type');
            });

            DB::table('did_numbers')->update([
                'routing_type_temp' => DB::raw('routing_type'),
            ]);

            Schema::table('did_numbers', function (Blueprint $table) {
                $table->dropColumn('routing_type');
            });

            Schema::table('did_numbers', function (Blueprint $table) {
                $table->enum('routing_type', [
                    'extension',
                    'ring_group',
                    'business_hours',
                    'conference_room',
                    'ai_assistant',
                    'ai_load_balancer',
                    'ivr_menu',
                ])->default('extension')->after('friendly_name');
            });

            DB::table('did_numbers')->update([
                'routing_type' => DB::raw('routing_type_temp'),
            ]);

            Schema::table('did_numbers', function (Blueprint $table) {
                $table->dropColumn('routing_type_temp');
            });
        } else {
            DB::statement("ALTER TABLE did_numbers MODIFY COLUMN routing_type ENUM('extension', 'ring_group', 'business_hours', 'conference_room', 'ai_assistant', 'ai_load_balancer', 'ivr_menu') DEFAULT 'extension'");
        }
    }
};
