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
        Schema::table('business_hours_schedules', function (Blueprint $table) {
            $table->enum('open_hours_action_type', ['extension', 'ring_group', 'ivr_menu'])
                ->after('open_hours_action')
                ->default('extension');
            $table->enum('closed_hours_action_type', ['extension', 'ring_group', 'ivr_menu'])
                ->after('closed_hours_action')
                ->default('extension');
        });

        // Transform existing string data to JSON format
        $this->transformExistingData();
    }

    /**
     * Transform existing string action data to JSON format.
     */
    private function transformExistingData(): void
    {
        // Transform open_hours_action
        DB::table('business_hours_schedules')
            ->whereNotNull('open_hours_action')
            ->where('open_hours_action', '!=', '')
            ->update([
                'open_hours_action' => DB::raw("JSON_OBJECT('type', 'extension', 'target_id', open_hours_action)"),
                'open_hours_action_type' => 'extension'
            ]);

        // Transform closed_hours_action
        DB::table('business_hours_schedules')
            ->whereNotNull('closed_hours_action')
            ->where('closed_hours_action', '!=', '')
            ->update([
                'closed_hours_action' => DB::raw("JSON_OBJECT('type', 'extension', 'target_id', closed_hours_action)"),
                'closed_hours_action_type' => 'extension'
            ]);

        // Handle empty/null values by setting default extension action
        DB::table('business_hours_schedules')
            ->where(function ($query) {
                $query->whereNull('open_hours_action')
                    ->orWhere('open_hours_action', '');
            })
            ->update([
                'open_hours_action' => DB::raw("JSON_OBJECT('type', 'extension', 'target_id', '')"),
                'open_hours_action_type' => 'extension'
            ]);

        DB::table('business_hours_schedules')
            ->where(function ($query) {
                $query->whereNull('closed_hours_action')
                    ->orWhere('closed_hours_action', '');
            })
            ->update([
                'closed_hours_action' => DB::raw("JSON_OBJECT('type', 'extension', 'target_id', '')"),
                'closed_hours_action_type' => 'extension'
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Transform JSON data back to string format for rollback
        $this->rollbackDataTransformation();

        Schema::table('business_hours_schedules', function (Blueprint $table) {
            $table->dropColumn(['open_hours_action_type', 'closed_hours_action_type']);
        });
    }

    /**
     * Rollback data transformation from JSON back to string format.
     */
    private function rollbackDataTransformation(): void
    {
        // Extract target_id from JSON for open_hours_action
        DB::statement("
            UPDATE business_hours_schedules
            SET open_hours_action = JSON_UNQUOTE(JSON_EXTRACT(open_hours_action, '$.target_id'))
            WHERE JSON_VALID(open_hours_action) = 1
        ");

        // Extract target_id from JSON for closed_hours_action
        DB::statement("
            UPDATE business_hours_schedules
            SET closed_hours_action = JSON_UNQUOTE(JSON_EXTRACT(closed_hours_action, '$.target_id'))
            WHERE JSON_VALID(closed_hours_action) = 1
        ");
    }
};