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
        Schema::create('call_tracking_ad_platform_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('google_ads_enabled')->default(false);
            $table->text('google_ads_developer_token')->nullable();
            $table->text('google_ads_refresh_token')->nullable();
            $table->string('google_ads_customer_id', 255)->nullable();
            $table->string('google_ads_conversion_action_resource_name', 1024)->nullable();
            $table->boolean('meta_enabled')->default(false);
            $table->string('meta_pixel_id', 255)->nullable();
            $table->text('meta_access_token')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_tracking_ad_platform_integrations');
    }
};
