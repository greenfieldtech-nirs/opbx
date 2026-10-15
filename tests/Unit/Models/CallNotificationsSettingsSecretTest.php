<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\CallNotificationsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CallNotificationsSettingsSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_secret_is_encrypted_at_rest_hidden_and_still_used_for_auth(): void
    {
        $settings = CallNotificationsSettings::factory()->create([
            'auth_method' => 'bearer_token',
            'auth_secret' => 'plain-secret-value-123',
        ]);

        $raw = DB::table('call_notifications_settings')->where('id', $settings->id)->value('auth_secret');
        $this->assertNotSame('plain-secret-value-123', $raw);
        $this->assertStringNotContainsString('plain-secret', (string) $raw);

        $fresh = $settings->refresh();
        $this->assertArrayNotHasKey('auth_secret', $fresh->toArray());
        $this->assertSame('plain-secret-value-123', $fresh->auth_secret);
        $this->assertSame('Bearer plain-secret-value-123', $fresh->getAuthHeaders([])['Authorization'] ?? null);
    }
}
