<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_dialer_campaigns', function (Blueprint $table): void {
            if (! Schema::hasColumn('auto_dialer_campaigns', 'retry_on_busy_no_answer')) {
                // Default false: busy / no-answer end as failed, as before for existing campaigns.
                $table->boolean('retry_on_busy_no_answer')->default(false)->after('retry_on_voicemail');
            }
            if (! Schema::hasColumn('auto_dialer_campaigns', 'retry_delay_minutes')) {
                $table->unsignedSmallInteger('retry_delay_minutes')->default(15)->after('retry_on_busy_no_answer');
            }
        });
    }

    public function down(): void
    {
        Schema::table('auto_dialer_campaigns', function (Blueprint $table): void {
            foreach (['retry_delay_minutes', 'retry_on_busy_no_answer'] as $column) {
                if (Schema::hasColumn('auto_dialer_campaigns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
