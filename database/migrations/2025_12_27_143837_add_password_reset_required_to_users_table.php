<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add password policy enforcement columns to users table.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Flag to force password reset on next login
            $table->boolean('password_reset_required')->default(false)->after('password');

            // Track when password was last changed for password age policies
            $table->timestamp('password_last_changed_at')->nullable()->after('password_reset_required');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_reset_required', 'password_last_changed_at']);
        });
    }
};
