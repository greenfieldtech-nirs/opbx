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
        Schema::table('organizations', function (Blueprint $table) {
            // Add status column if it doesn't exist
            if (! Schema::hasColumn('organizations', 'status')) {
                $table->string('status', 20)
                    ->default('active')
                    ->after('slug')
                    ->index('idx_organizations_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'status')) {
                $table->dropIndex('idx_organizations_status');
                $table->dropColumn('status');
            }
        });
    }
};
