<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('call_detail_records', function (Blueprint $table) {
            $table->id();

            // Multi-tenancy
            $table->foreignId('organization_id')
                ->constrained()
                ->onDelete('cascade');

            // Core CDR fields (mandatory)
            $table->timestamp('session_timestamp')->index();
            $table->string('session_token', 100)->nullable()->index();
            $table->string('from', 100)->index();
            $table->string('to', 100)->index();
            $table->string('disposition', 50)->index();
            $table->integer('duration')->default(0); // Total call duration in seconds
            $table->integer('billsec')->default(0); // Connected duration in seconds
            $table->string('call_id', 255)->index();

            // Additional CDR fields (optional but useful)
            $table->string('domain', 255)->nullable()->index();
            $table->string('subscriber', 100)->nullable();
            $table->integer('cx_trunk_id')->nullable();
            $table->string('application', 255)->nullable();
            $table->string('route', 255)->nullable();
            $table->decimal('rated_cost', 10, 4)->nullable();
            $table->decimal('approx_cost', 10, 4)->nullable();
            $table->decimal('sell_cost', 10, 4)->nullable();
            $table->string('vapp_server', 50)->nullable();

            // Session data
            $table->bigInteger('session_id')->nullable();
            $table->timestamp('call_start_time')->nullable();
            $table->timestamp('call_end_time')->nullable();
            $table->timestamp('call_answer_time')->nullable();
            $table->string('status', 50)->nullable();

            // Store complete CDR JSON for detailed view
            $table->json('raw_cdr');

            $table->timestamps();

            // Indexes for common queries (use short names to avoid MySQL 64-char limit)
            $table->index(['organization_id', 'session_timestamp'], 'cdr_org_ts_idx');
            $table->index(['organization_id', 'disposition'], 'cdr_org_disp_idx');
            $table->index(['organization_id', 'from'], 'cdr_org_from_idx');
            $table->index(['organization_id', 'to'], 'cdr_org_to_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_detail_records');
    }
};
