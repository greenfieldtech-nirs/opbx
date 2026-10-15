<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ciphertext is longer than the plaintext, so 512 chars is not enough.
        Schema::table('call_notifications_settings', function (Blueprint $table): void {
            $table->text('auth_secret')->nullable()->change();
        });

        foreach (DB::table('call_notifications_settings')->whereNotNull('auth_secret')->get(['id', 'auth_secret']) as $row) {
            try {
                Crypt::decryptString($row->auth_secret); // already encrypted
            } catch (DecryptException) {
                DB::table('call_notifications_settings')->where('id', $row->id)
                    ->update(['auth_secret' => Crypt::encryptString($row->auth_secret)]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('call_notifications_settings')->whereNotNull('auth_secret')->get(['id', 'auth_secret']) as $row) {
            try {
                DB::table('call_notifications_settings')->where('id', $row->id)
                    ->update(['auth_secret' => Crypt::decryptString($row->auth_secret)]);
            } catch (DecryptException) {
                // already plaintext
            }
        }

        Schema::table('call_notifications_settings', function (Blueprint $table): void {
            $table->string('auth_secret', 512)->nullable()->change();
        });
    }
};
