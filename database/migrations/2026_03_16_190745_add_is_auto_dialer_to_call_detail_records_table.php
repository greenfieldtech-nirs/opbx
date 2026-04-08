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
        Schema::table('call_detail_records', function (Blueprint $table) {
            $table->boolean('is_auto_dialer')->default(false)->after('raw_cdr');
            $table->foreignId('auto_dialer_campaign_id')->nullable()->constrained('auto_dialer_campaigns')->nullOnDelete()->after('is_auto_dialer');

            // Index for filtering auto-dialer calls
            $table->index(['is_auto_dialer', 'organization_id'], 'cdr_auto_dialer_org_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('call_detail_records', function (Blueprint $table) {
            $table->dropIndex('cdr_auto_dialer_org_idx');
            $table->dropForeign(['auto_dialer_campaign_id']);
            $table->dropColumn('auto_dialer_campaign_id');
            $table->dropColumn('is_auto_dialer');
        });
    }
};
