<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('status', ['active', 'inactive', 'pending'])
                ->default('active')
                ->change();
        });
    }

    public function down(): void
    {
        // Revert any pending rows to inactive to keep enum valid
        DB::table('users')->where('status', 'pending')->update(['status' => 'inactive']);

        Schema::table('users', function (Blueprint $table) {
            $table->enum('status', ['active', 'inactive'])
                ->default('active')
                ->change();
        });
    }
};
