<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_calls', function (Blueprint $table): void {
            // The extension or number the agent blind-transferred the call to (null: not transferred).
            $table->string('transferred_to', 32)->nullable()->after('disposition');
        });
    }

    public function down(): void
    {
        Schema::table('queue_calls', function (Blueprint $table): void {
            $table->dropColumn('transferred_to');
        });
    }
};
