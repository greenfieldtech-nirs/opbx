<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The API rejects hmac_sha256 and the model never sends HMAC headers (it behaves as "none"),
 * but the column default was still hmac_sha256. Make the DB default match the model ('none')
 * and convert existing hmac_sha256 rows to 'none' (same effective behaviour: no auth headers).
 * The enum keeps all values so the change is reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('call_notifications_settings')->where('auth_method', 'hmac_sha256')->update(['auth_method' => 'none']);

        DB::statement("ALTER TABLE call_notifications_settings MODIFY COLUMN auth_method ENUM('hmac_sha256', 'bearer_token', 'basic_auth', 'none') NOT NULL DEFAULT 'none'");
    }

    public function down(): void
    {
        // Restores the old column default only; rows converted by up() stay 'none'.
        DB::statement("ALTER TABLE call_notifications_settings MODIFY COLUMN auth_method ENUM('hmac_sha256', 'bearer_token', 'basic_auth', 'none') NOT NULL DEFAULT 'hmac_sha256'");
    }
};
