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
            // Update role enum to match UserRole enum values
            // Change from ['owner', 'admin', 'agent'] to ['owner', 'pbx_admin', 'pbx_user', 'reporter']
            $table->enum('role', ['owner', 'pbx_admin', 'pbx_user', 'reporter'])
                ->default('pbx_user')
                ->change();

            // Update status enum to remove 'suspended' (keep only active/inactive)
            $table->enum('status', ['active', 'inactive'])
                ->default('active')
                ->change();

            // Add contact information fields (only if they don't exist)
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 50)->nullable()->after('status');
            }
            if (! Schema::hasColumn('users', 'street_address')) {
                $table->string('street_address', 255)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('users', 'city')) {
                $table->string('city', 100)->nullable()->after('street_address');
            }
            if (! Schema::hasColumn('users', 'state_province')) {
                $table->string('state_province', 100)->nullable()->after('city');
            }
            if (! Schema::hasColumn('users', 'postal_code')) {
                $table->string('postal_code', 20)->nullable()->after('state_province');
            }
            if (! Schema::hasColumn('users', 'country')) {
                $table->string('country', 100)->nullable()->after('postal_code');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Revert role enum to original values
            $table->enum('role', ['owner', 'admin', 'agent'])
                ->default('agent')
                ->change();

            // Revert status enum to original values
            $table->enum('status', ['active', 'inactive', 'suspended'])
                ->default('active')
                ->change();

            // Remove contact information fields (only if they exist)
            $columnsToCheck = [
                'phone',
                'street_address',
                'city',
                'state_province',
                'postal_code',
                'country',
            ];

            $columnsToDrop = [];
            foreach ($columnsToCheck as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
