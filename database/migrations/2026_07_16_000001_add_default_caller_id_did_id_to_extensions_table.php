<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table): void {
            $table->foreignId('default_caller_id_did_id')
                ->nullable()
                ->after('ai_assistant_id')
                ->constrained('did_numbers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_caller_id_did_id');
        });
    }
};
