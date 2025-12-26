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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->after('id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'agent'])->default('agent')->after('email');
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->after('role');

            $table->index(['organization_id', 'status']);
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn(['organization_id', 'role', 'status']);
        });
    }
};
