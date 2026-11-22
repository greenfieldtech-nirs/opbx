<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_embed_tokens', function (Blueprint $table): void {
            // Whether the embedded dialer offers in-call transfer (off unless the admin enables it).
            $table->boolean('allow_transfer')->default(false)->after('icon_background_color');
        });
    }

    public function down(): void
    {
        Schema::table('user_embed_tokens', function (Blueprint $table): void {
            $table->dropColumn('allow_transfer');
        });
    }
};
