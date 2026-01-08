<?php

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
        Schema::table('cloudonix_settings', function (Blueprint $table) {
            $table->string('cloudonix_package')->nullable()->after('recording_format');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cloudonix_settings', function (Blueprint $table) {
            $table->dropColumn('cloudonix_package');
        });
    }
};
