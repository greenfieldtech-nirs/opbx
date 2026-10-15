<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warm CDR tier: mirrors call_detail_records on the cdr_archive connection and keeps the original id.
 * No foreign keys (the schema may be a different one or a different server). Idempotent.
 */
return new class extends Migration
{
    protected $connection = 'cdr_archive';

    public function up(): void
    {
        if (Schema::hasTable('archived_call_detail_records')) {
            return;
        }

        Schema::create('archived_call_detail_records', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->timestamp('session_timestamp');
            $table->string('session_token', 100)->nullable();
            $table->string('from', 100);
            $table->string('to', 100);
            $table->string('direction', 20)->nullable();
            $table->string('disposition', 50);
            $table->integer('duration')->default(0);
            $table->integer('billsec')->default(0);
            $table->string('call_id', 255);
            $table->string('domain', 255)->nullable();
            $table->string('subscriber', 100)->nullable();
            $table->integer('cx_trunk_id')->nullable();
            $table->string('application', 255)->nullable();
            $table->string('route', 255)->nullable();
            $table->decimal('rated_cost', 10, 4)->nullable();
            $table->decimal('approx_cost', 10, 4)->nullable();
            $table->decimal('sell_cost', 10, 4)->nullable();
            $table->string('vapp_server', 50)->nullable();
            $table->bigInteger('session_id')->nullable();
            $table->timestamp('call_start_time')->nullable();
            $table->timestamp('call_end_time')->nullable();
            $table->timestamp('call_answer_time')->nullable();
            $table->string('status', 50)->nullable();
            $table->string('amd_result', 20)->nullable();
            $table->decimal('amd_confidence', 5, 2)->nullable();
            $table->enum('recording_status', ['none', 'pending', 'available', 'failed'])->default('none');
            $table->string('recording_source_url')->nullable();
            $table->string('recording_stored_path')->nullable();
            $table->string('recording_mime_type')->nullable();
            $table->integer('recording_duration')->nullable();
            $table->json('raw_cdr');
            $table->boolean('is_auto_dialer')->default(false);
            $table->unsignedBigInteger('auto_dialer_campaign_id')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'session_timestamp'], 'acdr_org_ts_idx');
            $table->index('session_timestamp', 'acdr_ts_idx');
            $table->index(['organization_id', 'from'], 'acdr_org_from_idx');
            $table->index(['organization_id', 'to'], 'acdr_org_to_idx');
            $table->index('session_token', 'acdr_token_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archived_call_detail_records');
    }
};
