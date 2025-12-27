<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        // Drop old business_hours table (if it exists, recreate with new schema)
        Schema::dropIfExists('business_hours');

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

            $table->index(['organization_id', 'status']);
            $table->index('deleted_at');
        });

        // Create business_hours_schedule_days table
        Schema::create('business_hours_schedule_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_hours_schedule_id')
                ->constrained('business_hours_schedules')
                ->cascadeOnDelete();
            $table->enum('day_of_week', [
                'monday', 'tuesday', 'wednesday', 'thursday',
                'friday', 'saturday', 'sunday'
            ]);
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['business_hours_schedule_id', 'day_of_week'], 'schedule_day_unique');
            $table->index('business_hours_schedule_id');
        });

        // Create business_hours_time_ranges table
        Schema::create('business_hours_time_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_hours_schedule_day_id')
                ->constrained('business_hours_schedule_days')
                ->cascadeOnDelete();
            $table->time('start_time'); // HH:mm format
            $table->time('end_time');   // HH:mm format
            $table->timestamps();

            $table->index('business_hours_schedule_day_id');
        });

        // Create business_hours_exceptions table
        Schema::create('business_hours_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_hours_schedule_id')
                ->constrained('business_hours_schedules')
                ->cascadeOnDelete();
            $table->date('date'); // YYYY-MM-DD format
            $table->string('name');
            $table->enum('type', ['closed', 'special_hours'])->default('closed');
            $table->timestamps();

            $table->index('business_hours_schedule_id');
            $table->index('date');
        });

        // Create business_hours_exception_time_ranges table
        Schema::create('business_hours_exception_time_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_hours_exception_id')
                ->constrained('business_hours_exceptions')
                ->cascadeOnDelete();
            $table->time('start_time'); // HH:mm format
            $table->time('end_time');   // HH:mm format
            $table->timestamps();

            $table->index('business_hours_exception_id');
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
