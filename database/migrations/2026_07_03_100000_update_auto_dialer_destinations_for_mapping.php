<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_dialer_destinations', function (Blueprint $table) {
            if (! Schema::hasColumn('auto_dialer_destinations', 'name')) {
                $table->string('name', 255)->nullable()->after('phone_number');
            }
            if (! Schema::hasColumn('auto_dialer_destinations', 'batch_identifier')) {
                $table->string('batch_identifier', 255)->nullable()->after('name');
            }
            if (! Schema::hasColumn('auto_dialer_destinations', 'metadata')) {
                $table->json('metadata')->nullable()->after('batch_identifier');
            }
            if (Schema::hasColumn('auto_dialer_destinations', 'description')) {
                $table->dropColumn('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('auto_dialer_destinations', function (Blueprint $table) {
            $table->dropColumnIfExists(['name', 'batch_identifier', 'metadata']);
            if (! Schema::hasColumn('auto_dialer_destinations', 'description')) {
                $table->string('description', 255)->nullable()->after('phone_number');
            }
        });
    }
};
