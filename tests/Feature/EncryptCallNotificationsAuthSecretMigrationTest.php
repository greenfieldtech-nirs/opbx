<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CallNotificationsSettings;
use App\Scopes\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The migration rewrites existing production rows, so prove it on a
 * plaintext row: encrypts once, is idempotent, and rolls back to plaintext.
 */
class EncryptCallNotificationsAuthSecretMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_plaintext_secret_is_encrypted_idempotently_and_reversibly(): void
    {
        $settings = CallNotificationsSettings::factory()->create();
        DB::table('call_notifications_settings')->where('id', $settings->id)->update(['auth_secret' => 'legacy-plain-secret']);
        $migration = require database_path('migrations/2026_10_12_000002_encrypt_call_notifications_auth_secret.php');

        $migration->up();
        $migration->up(); // already-encrypted rows must be left alone

        $stored = DB::table('call_notifications_settings')->where('id', $settings->id)->value('auth_secret');
        $this->assertNotSame('legacy-plain-secret', $stored);
        $this->assertSame('legacy-plain-secret', OrganizationScope::bypass(fn () => $settings->fresh()->auth_secret));

        $migration->down();
        $this->assertSame('legacy-plain-secret', DB::table('call_notifications_settings')->where('id', $settings->id)->value('auth_secret'));
        $migration->up(); // leave the schema as RefreshDatabase expects
    }
}
