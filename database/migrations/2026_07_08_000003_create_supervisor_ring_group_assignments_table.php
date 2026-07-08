<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisor_ring_group_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supervisor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ring_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['supervisor_id', 'ring_group_id'], 'supervisor_ring_group_unique');
            $table->index(['organization_id', 'supervisor_id'], 'supervisor_ring_group_org_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_ring_group_assignments');
    }
};
