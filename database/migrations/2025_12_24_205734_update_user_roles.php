<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Updates the user roles from the old system (owner, admin, agent)
     * to the new system (owner, pbx_admin, pbx_user, reporter).
     */
    public function up(): void
    {
        // First, migrate existing data to new role names
        // admin -> pbx_admin
        DB::table('users')
            ->where('role', 'admin')
            ->update(['role' => 'pbx_admin']);

        // agent -> pbx_user
        DB::table('users')
            ->where('role', 'agent')
            ->update(['role' => 'pbx_user']);

        // owner stays as owner (no change needed)

        // Now update the enum column definition to include new roles
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['owner', 'pbx_admin', 'pbx_user', 'reporter'])
                ->default('pbx_user')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Reverts the role changes back to the old system.
     */
    public function down(): void
    {
        // First, revert the enum column to old values
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['owner', 'admin', 'agent'])
                ->default('agent')
                ->change();
        });

        // Then migrate data back to old role names
        // pbx_admin -> admin
        DB::table('users')
            ->where('role', 'pbx_admin')
            ->update(['role' => 'admin']);

        // pbx_user -> agent
        DB::table('users')
            ->where('role', 'pbx_user')
            ->update(['role' => 'agent']);

        // reporter -> agent (fallback to agent for reporters)
        DB::table('users')
            ->where('role', 'reporter')
            ->update(['role' => 'agent']);
    }
};
