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
        Schema::table('call_tracking_campaigns', function (Blueprint $table) {
            $table->boolean('google_ads_upload_enabled')->default(false)->after('conversion_rule');
            $table->boolean('meta_upload_enabled')->default(false)->after('google_ads_upload_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_tracking_campaigns', function (Blueprint $table) {
            $table->dropColumn(['google_ads_upload_enabled', 'meta_upload_enabled']);
        });
    }
};
