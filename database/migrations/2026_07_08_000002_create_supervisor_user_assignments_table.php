<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisor_user_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supervisor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['supervisor_id', 'user_id']);
            $table->index(['organization_id', 'supervisor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_user_assignments');
    }
};
