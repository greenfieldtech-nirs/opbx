<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_health_checks')) {
            return;
        }

        Schema::create('platform_health_checks', function (Blueprint $table) {
            $table->id();
            $table->string('component', 50);
            $table->string('status', 20);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['component', 'checked_at']);
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_health_checks');
    }
};
