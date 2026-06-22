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
        Schema::create('call_tracking_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_tracking_campaign_id');
            $table->foreignId('did_number_id')->constrained('did_numbers')->cascadeOnDelete();
            $table->string('friendly_name', 255)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->foreign('call_tracking_campaign_id', 'ct_numbers_campaign_fk')
                ->references('id')
                ->on('call_tracking_campaigns')
                ->onDelete('cascade');

            $table->unique('did_number_id', 'ct_numbers_did_unique');
            $table->index(['organization_id', 'call_tracking_campaign_id'], 'ct_numbers_org_campaign_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_tracking_numbers');
    }
};
