<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\AiAssistant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A provider field's configured validation_rules (config/ai-providers.php) are enforced on create and update,
 * e.g. DeepDub's Bot ID (max 255) and Authentication Token (max 512).
 */
class AiAssistantProviderFieldRulesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function deepdub(string $botId, string $token): array
    {
        return [
            'name' => 'DeepDub '.uniqid(),
            'provider' => 'deepdub',
            'configuration' => ['bot_id' => $botId, 'auth_token' => $token],
        ];
    }

    public function test_values_at_the_limits_are_accepted(): void
    {
        $this->postJson('/api/v1/ai-assistants', $this->deepdub(str_repeat('b', 255), str_repeat('t', 512)))
            ->assertCreated();
    }

    public function test_a_bot_id_over_255_characters_is_rejected(): void
    {
        $this->postJson('/api/v1/ai-assistants', $this->deepdub(str_repeat('b', 256), 'token'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['configuration.bot_id' => 'Bot ID'])
            ->assertJsonMissingValidationErrors('configuration.auth_token');
    }

    public function test_an_auth_token_over_512_characters_is_rejected(): void
    {
        $this->postJson('/api/v1/ai-assistants', $this->deepdub('bot', str_repeat('t', 513)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['configuration.auth_token' => 'Authentication Token'])
            ->assertJsonMissingValidationErrors('configuration.bot_id');
    }

    public function test_update_enforces_the_limits(): void
    {
        $assistant = AiAssistant::factory()->create(['organization_id' => $this->organization->id]);

        $this->putJson("/api/v1/ai-assistants/{$assistant->id}", $this->deepdub('bot', str_repeat('t', 513)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('configuration.auth_token');

        $this->putJson("/api/v1/ai-assistants/{$assistant->id}", $this->deepdub(str_repeat('b', 255), str_repeat('t', 512)))
            ->assertOk();
    }

    public function test_a_sip_number_without_the_plus_must_be_marked_non_e164(): void
    {
        $this->postJson('/api/v1/ai-assistants', [
            'name' => 'SIP',
            'provider' => 'vapi',
            'configuration' => ['phone_number' => '12025550170'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['configuration.phone_number' => 'mark it as non-E.164']);

        $this->postJson('/api/v1/ai-assistants', [
            'name' => 'SIP',
            'provider' => 'vapi',
            'configuration' => ['phone_number' => '+12025550170'],
        ])->assertCreated();
    }

    public function test_generic_fields_use_their_rules_too(): void
    {
        // Dograh Cloud's Agent UUID declares max:255.
        $this->postJson('/api/v1/ai-assistants', [
            'name' => 'Dograh',
            'provider' => 'dograh-cloud',
            'configuration' => ['agent_uuid' => str_repeat('a', 256)],
        ])->assertUnprocessable()->assertJsonValidationErrors('configuration.agent_uuid');
    }
}
