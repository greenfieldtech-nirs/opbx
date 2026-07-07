<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('email', 255);
            $table->string('name', 255)->nullable();
            $table->string('provider', 32);
            $table->string('provider_subject', 255);
            $table->string('status', 32)->default('pending');
            $table->string('role', 32)->default('pbx_user');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->unique(['organization_id', 'email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_join_requests');
    }
};
