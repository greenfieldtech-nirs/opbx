<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\AiAssistant;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\VoiceRouting\Strategies\AiAgentRoutingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A SIP AI assistant's number is either valid E.164 or explicitly marked non-E.164 with
 * configuration.enable_non_e164 (the same flag and rule as a non-E.164 phone number); the resource says which.
 */
final class AiAssistantNonE164NumberTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]));
    }

    /** @param  array<string, mixed>  $configuration */
    private function create(array $configuration): TestResponse
    {
        return $this->postJson('/api/v1/ai-assistants', [
            'name' => 'SIP '.uniqid(),
            'provider' => 'vapi',
            'configuration' => $configuration,
        ]);
    }

    public function test_an_e164_number_is_accepted_and_reported_as_e164(): void
    {
        $this->create(['phone_number' => '+12025550170'])
            ->assertCreated()
            ->assertJsonPath('data.configuration.phone_number', '+12025550170')
            ->assertJsonPath('data.configuration.enable_non_e164', false);
    }

    public function test_a_number_without_plus_is_refused_unless_marked_non_e164(): void
    {
        $this->create(['phone_number' => '12121212'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['configuration.phone_number' => 'non-E.164']);
    }

    public function test_a_number_marked_non_e164_is_accepted_and_stored_as_entered(): void
    {
        $id = $this->create(['phone_number' => '12121212', 'enable_non_e164' => true])
            ->assertCreated()
            ->assertJsonPath('data.configuration.phone_number', '12121212')
            ->assertJsonPath('data.configuration.enable_non_e164', true)
            ->json('data.id');

        $this->assertSame(['phone_number' => '12121212'], AiAssistant::findOrFail($id)->configuration);
        $this->getJson("/api/v1/ai-assistants/{$id}")->assertJsonPath('data.configuration.enable_non_e164', true);
    }

    public function test_a_non_e164_number_follows_the_phone_number_rule(): void
    {
        $this->create(['phone_number' => '*99#12', 'enable_non_e164' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('configuration.phone_number');
        $this->create(['phone_number' => str_repeat('1', 21), 'enable_non_e164' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('configuration.phone_number');
        $this->create(['phone_number' => '0099#12', 'enable_non_e164' => true])->assertCreated();
    }

    public function test_a_legacy_assistant_without_plus_reads_as_non_e164_and_resaves(): void
    {
        $legacy = AiAssistant::factory()->sip()->create([
            'organization_id' => $this->org->id,
            'configuration' => ['phone_number' => '12121212'],
        ]);

        $read = $this->getJson("/api/v1/ai-assistants/{$legacy->id}")
            ->assertOk()
            ->assertJsonPath('data.configuration.enable_non_e164', true)
            ->json('data');

        $this->putJson("/api/v1/ai-assistants/{$legacy->id}", [
            'name' => $read['name'],
            'provider' => $read['provider'],
            'configuration' => $read['configuration'],
        ])->assertOk()->assertJsonPath('data.configuration.phone_number', '12121212');

        // Saved without the flag, the same number is refused.
        $this->putJson("/api/v1/ai-assistants/{$legacy->id}", [
            'provider' => 'vapi',
            'configuration' => ['phone_number' => '12121212'],
        ])->assertUnprocessable()->assertJsonValidationErrors('configuration.phone_number');
    }

    public function test_routing_dials_the_number_as_stored(): void
    {
        $assistant = AiAssistant::factory()->sip()->create([
            'organization_id' => $this->org->id,
            'configuration' => ['phone_number' => '12121212'],
        ]);
        $extension = Extension::factory()->create([
            'organization_id' => $this->org->id,
            'type' => ExtensionType::AI_ASSISTANT,
            'ai_assistant_id' => $assistant->id,
            'configuration' => ['ai_assistant_id' => $assistant->id],
        ]);
        $request = Request::create('/voice', 'POST', ['CallSid' => 'CA1', 'From' => '+15550001', 'To' => '+15550002']);

        $xml = (string) app(AiAgentRoutingStrategy::class)->route($request, new DidNumber, ['extension' => $extension])->getContent();

        $this->assertStringContainsString('<Service provider="vapi">12121212</Service>', $xml);
    }
}
