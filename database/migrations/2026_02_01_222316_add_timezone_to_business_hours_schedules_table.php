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
            $table->string('timezone', 50)->after('status')->default('UTC');
        });

        // Update existing records to use the organization's timezone or UTC
        // Use SQLite-compatible syntax
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement("
                UPDATE business_hours_schedules
                SET timezone = COALESCE(
                    (SELECT timezone FROM organizations WHERE id = business_hours_schedules.organization_id),
                    'UTC'
                )
            ");
        } else {
            // MySQL syntax
            DB::statement("
                UPDATE business_hours_schedules bhs
                JOIN organizations o ON bhs.organization_id = o.id
                SET bhs.timezone = COALESCE(o.timezone, 'UTC')
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_hours_schedules', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
