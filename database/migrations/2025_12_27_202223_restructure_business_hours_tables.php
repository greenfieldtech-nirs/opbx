<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restructure business hours tables to match the normalized frontend schema.
 *
 * This migration:
 * 1. Renames business_hours table to business_hours_schedules
 * 2. Updates columns to match frontend expectations
 * 3. Creates related tables for days, time ranges, exceptions, and exception time ranges
 *
 * WARNING: This migration will drop and recreate tables, losing existing data.
 * If you have production data, create a custom migration to preserve it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Disable foreign key checks to allow dropping tables
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Drop all tables in reverse order
        Schema::dropIfExists('business_hours_exception_time_ranges');
        Schema::dropIfExists('business_hours_exceptions');
        Schema::dropIfExists('business_hours_time_ranges');
        Schema::dropIfExists('business_hours_schedule_days');
        Schema::dropIfExists('business_hours_schedules');
        Schema::dropIfExists('business_hours');

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Create business_hours_schedules table
        Schema::create('business_hours_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->string('open_hours_action');  // Extension ID for open hours routing
            $table->string('closed_hours_action'); // Extension ID for closed hours routing
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status'], 'bh_schedules_org_status_idx');
            $table->index('deleted_at', 'bh_schedules_deleted_idx');
        });

        // Create business_hours_schedule_days table
        Schema::create('business_hours_schedule_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_hours_schedule_id');
            $table->enum('day_of_week', [
                'monday', 'tuesday', 'wednesday', 'thursday',
                'friday', 'saturday', 'sunday'
            ]);
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            // Foreign key with custom short name
            $table->foreign('business_hours_schedule_id', 'bh_schedule_days_schedule_fk')
                ->references('id')
                ->on('business_hours_schedules')
                ->cascadeOnDelete();

            $table->unique(['business_hours_schedule_id', 'day_of_week'], 'bh_schedule_days_unique');
            $table->index('business_hours_schedule_id', 'bh_schedule_days_schedule_idx');
        });

        // Create business_hours_time_ranges table
        Schema::create('business_hours_time_ranges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_hours_schedule_day_id');
            $table->time('start_time'); // HH:mm format
            $table->time('end_time');   // HH:mm format
            $table->timestamps();

            // Foreign key with custom short name
            $table->foreign('business_hours_schedule_day_id', 'bh_time_ranges_day_fk')
                ->references('id')
                ->on('business_hours_schedule_days')
                ->cascadeOnDelete();

            $table->index('business_hours_schedule_day_id', 'bh_time_ranges_day_idx');
        });

        // Create business_hours_exceptions table
        Schema::create('business_hours_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_hours_schedule_id');
            $table->date('date'); // YYYY-MM-DD format
            $table->string('name');
            $table->enum('type', ['closed', 'special_hours'])->default('closed');
            $table->timestamps();

            // Foreign key with custom short name
            $table->foreign('business_hours_schedule_id', 'bh_exceptions_schedule_fk')
                ->references('id')
                ->on('business_hours_schedules')
                ->cascadeOnDelete();

            $table->index('business_hours_schedule_id', 'bh_exceptions_schedule_idx');
            $table->index('date', 'bh_exceptions_date_idx');
        });

        // Create business_hours_exception_time_ranges table
        Schema::create('business_hours_exception_time_ranges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_hours_exception_id');
            $table->time('start_time'); // HH:mm format
            $table->time('end_time');   // HH:mm format
            $table->timestamps();

            // Foreign key with custom short name
            $table->foreign('business_hours_exception_id', 'bh_exception_time_ranges_fk')
                ->references('id')
                ->on('business_hours_exceptions')
                ->cascadeOnDelete();

            $table->index('business_hours_exception_id', 'bh_exception_time_ranges_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_hours_exception_time_ranges');
        Schema::dropIfExists('business_hours_exceptions');
        Schema::dropIfExists('business_hours_time_ranges');
        Schema::dropIfExists('business_hours_schedule_days');
        Schema::dropIfExists('business_hours_schedules');

        // Recreate old business_hours table
        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('timezone')->default('UTC');
            $table->json('schedules');
            $table->json('holidays')->nullable();
            $table->json('open_hours_routing');
            $table->json('closed_hours_routing');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }
};
