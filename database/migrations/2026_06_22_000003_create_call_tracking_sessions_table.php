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
        Schema::create('call_tracking_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_tracking_campaign_id');
            $table->foreignId('call_tracking_number_id');
            $table->foreignId('did_number_id')->constrained('did_numbers')->cascadeOnDelete();
            $table->string('call_id', 255)->index('ct_sessions_call_id_idx');
            $table->string('session_id', 255)->nullable()->index('ct_sessions_session_id_idx');
            $table->string('caller_number', 50);
            $table->string('caller_country', 10)->nullable();
            $table->string('called_number', 50);
            $table->string('source', 100)->nullable();
            $table->string('medium', 100)->nullable();
            $table->string('campaign_name', 255)->nullable();
            $table->string('disposition', 50);
            $table->unsignedInteger('duration')->default(0);
            $table->unsignedInteger('billsec')->default(0);
            $table->boolean('is_answered')->default(false);
            $table->boolean('is_converted')->default(false);
            $table->decimal('conversion_value', 12, 4)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('raw_cdr');
            $table->timestamps();

            $table->foreign('call_tracking_campaign_id', 'ct_sessions_campaign_fk')
                ->references('id')
                ->on('call_tracking_campaigns')
                ->onDelete('cascade');
            $table->foreign('call_tracking_number_id', 'ct_sessions_number_fk')
                ->references('id')
                ->on('call_tracking_numbers')
                ->onDelete('cascade');

            $table->index(['organization_id', 'call_tracking_campaign_id', 'started_at'], 'ct_sessions_org_campaign_started_idx');
            $table->index(['organization_id', 'started_at'], 'ct_sessions_org_started_idx');
            $table->index(['called_number', 'started_at'], 'ct_sessions_called_started_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_tracking_sessions');
    }
};
