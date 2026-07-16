<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_whitelists', function (Blueprint $table): void {
            $table->foreignId('default_caller_id_did_id')
                ->nullable()
                ->after('outbound_trunk_name')
                ->constrained('did_numbers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outbound_whitelists', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_caller_id_did_id');
        });
    }
};
