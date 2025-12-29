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
        Schema::table('cloudonix_settings', function (Blueprint $table): void {
            // Domain name from Cloudonix (e.g., "sample.cloudonix.net")
            $table->string('domain_name')->nullable()->after('domain_uuid');

            // Custom webhook base URL for this organization
            // If not set, defaults to APP_URL
            $table->string('webhook_base_url')->nullable()->after('domain_requests_api_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cloudonix_settings', function (Blueprint $table): void {
            $table->dropColumn(['domain_name', 'webhook_base_url']);
        });
    }
};
