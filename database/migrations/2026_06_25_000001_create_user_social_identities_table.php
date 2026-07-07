<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_social_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('provider', 32);
            $table->string('provider_subject', 255);
            $table->string('provider_email', 255)->nullable();
            $table->json('provider_data')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_subject']);
            $table->unique(['user_id', 'provider']);
            $table->index('provider_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_identities');
    }
};
