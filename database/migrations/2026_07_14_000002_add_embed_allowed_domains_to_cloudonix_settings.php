<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cloudonix_settings', function (Blueprint $table) {
            // Organization-level allowlist of hostnames permitted to embed the
            // Web Phone dialer. Single source of truth for the embed feature.
            $table->json('embed_allowed_domains')->nullable()->after('webhook_base_url');
        });
    }

    public function down(): void
    {
        Schema::table('cloudonix_settings', function (Blueprint $table) {
            $table->dropColumn('embed_allowed_domains');
        });
    }
};
