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
        // Empty strings would collide under a unique index; NULLs do not.
        DB::table('cloudonix_settings')->where('domain_name', '')->update(['domain_name' => null]);
        DB::table('cloudonix_settings')->where('domain_uuid', '')->update(['domain_uuid' => null]);

        Schema::table('cloudonix_settings', function (Blueprint $table) {
            $table->unique('domain_name');
            $table->unique('domain_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('cloudonix_settings', function (Blueprint $table) {
            $table->dropUnique(['domain_name']);
            $table->dropUnique(['domain_uuid']);
        });
    }
};
