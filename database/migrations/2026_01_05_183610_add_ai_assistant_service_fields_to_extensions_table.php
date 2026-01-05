<?php

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
        Schema::table('extensions', function (Blueprint $table) {
            $table->string('service_url')->nullable()->after('configuration');
            $table->string('service_token')->nullable()->after('service_url');
            $table->json('service_params')->nullable()->after('service_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropColumn(['service_url', 'service_token', 'service_params']);
        });
    }
};
