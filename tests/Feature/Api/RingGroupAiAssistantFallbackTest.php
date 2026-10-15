<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ExtensionType;
use App\Enums\RingGroupFallbackAction;
use App\Enums\UserRole;
use App\Models\AiAssistant;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use App\Services\VoiceRouting\Strategies\RingGroupRoutingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A ring group's AI-assistant fallback references ai_assistants, like call queues and AI load balancers. */
final class RingGroupAiAssistantFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_15_100000_point_ring_group_ai_assistant_fallback_at_ai_assistants.php';

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create(['organization_id' => $this->org->id, 'role' => UserRole::OWNER]));
    }

    /** @return array<string, mixed> */
    private function payload(?int $assistantId): array
    {
        $member = Extension::factory()->create(['organization_id' => $this->org->id]);

        return [
            'name' => 'RG', 'strategy' => 'simultaneous', 'timeout' => 30, 'ring_turns' => 1, 'status' => 'active',
            'fallback_action' => 'ai_assistant', 'fallback_ai_assistant_id' => $assistantId,
            'members' => [['extension_id' => $member->id, 'priority' => 1]],
        ];
    }

    public function test_store_accepts_an_active_ai_assistant_of_the_organization(): void
    {
        $assistant = AiAssistant::factory()->create(['organization_id' => $this->org->id]);

        $id = $this->postJson('/api/v1/ring-groups', $this->payload($assistant->id))->assertCreated()
            ->assertJsonPath('data.fallback_ai_assistant_id', $assistant->id)->json('data.id');

        $this->assertTrue(RingGroup::find($id)->fallbackAiAssistant->is($assistant));
    }

    public function test_store_rejects_unknown_inactive_and_foreign_assistants(): void
    {
        $inactive = AiAssistant::factory()->inactive()->create(['organization_id' => $this->org->id]);
        $foreign = AiAssistant::factory()->create();

        $this->postJson('/api/v1/ring-groups', $this->payload((int) AiAssistant::max('id') + 1))->assertUnprocessable()->assertJsonValidationErrors(['fallback_ai_assistant_id']);
        $this->postJson('/api/v1/ring-groups', $this->payload($inactive->id))->assertUnprocessable()->assertJsonValidationErrors(['fallback_ai_assistant_id']);
        $this->postJson('/api/v1/ring-groups', $this->payload($foreign->id))->assertUnprocessable()->assertJsonValidationErrors(['fallback_ai_assistant_id']);
    }

    public function test_fallback_routes_to_the_assistant(): void
    {
        $assistant = AiAssistant::factory()->create(['organization_id' => $this->org->id, 'configuration' => ['phone_number' => '+15557770000']]);
        $group = RingGroup::factory()->create(['organization_id' => $this->org->id]);
        $group->update(['fallback_action' => RingGroupFallbackAction::AI_ASSISTANT, 'fallback_ai_assistant_id' => $assistant->id]);

        $method = new \ReflectionMethod(RingGroupRoutingStrategy::class, 'handleFallback');
        $xml = (string) $method->invoke(app(RingGroupRoutingStrategy::class), $group->fresh(), Request::create('/voice', 'POST', ['CallSid' => 'CA1']))->getContent();

        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringContainsString('+15557770000', $xml);
    }

    public function test_deleting_the_assistant_is_blocked_by_the_ring_group_fallback(): void
    {
        $assistant = AiAssistant::factory()->create(['organization_id' => $this->org->id]);
        $group = RingGroup::factory()->create(['organization_id' => $this->org->id]);
        $group->update(['fallback_action' => RingGroupFallbackAction::AI_ASSISTANT, 'fallback_ai_assistant_id' => $assistant->id]);

        $this->deleteJson("/api/v1/ai-assistants/{$assistant->id}")->assertStatus(409)
            ->assertJsonFragment(['type' => 'ring_group', 'id' => $group->id, 'name' => $group->name]);

        $group->update(['fallback_ai_assistant_id' => null]);
        $this->deleteJson("/api/v1/ai-assistants/{$assistant->id}")->assertNoContent();
    }

    public function test_foreign_key_references_ai_assistants(): void
    {
        $fk = collect(Schema::getForeignKeys('ring_groups'))->first(fn (array $fk): bool => $fk['columns'] === ['fallback_ai_assistant_id']);

        $this->assertSame('ai_assistants', $fk['foreign_table']);
        $this->assertSame('set null', strtolower((string) $fk['on_delete']));
    }

    public function test_migration_converts_extension_ids_and_is_idempotent(): void
    {
        $assistant = AiAssistant::factory()->create(['organization_id' => $this->org->id]);
        $aiExtension = Extension::factory()->create(['organization_id' => $this->org->id, 'type' => ExtensionType::AI_ASSISTANT, 'ai_assistant_id' => $assistant->id]);
        $plainExtension = Extension::factory()->create(['organization_id' => $this->org->id]);
        $converted = RingGroup::factory()->create(['organization_id' => $this->org->id]);
        $orphaned = RingGroup::factory()->create(['organization_id' => $this->org->id]);

        // Rows as they were before the migration: extension ids in the column.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('ring_groups')->where('id', $converted->id)->update(['fallback_ai_assistant_id' => $aiExtension->id]);
        DB::table('ring_groups')->where('id', $orphaned->id)->update(['fallback_ai_assistant_id' => $plainExtension->id]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $migration = include database_path('migrations/'.self::MIGRATION);
        $migration->convertExtensionIdsToAiAssistantIds();

        $this->assertSame($assistant->id, (int) DB::table('ring_groups')->where('id', $converted->id)->value('fallback_ai_assistant_id'));
        $this->assertNull(DB::table('ring_groups')->where('id', $orphaned->id)->value('fallback_ai_assistant_id'));

        // The schema already points at ai_assistants: running up() again changes nothing.
        $migration->up();
        $this->assertSame($assistant->id, (int) DB::table('ring_groups')->where('id', $converted->id)->value('fallback_ai_assistant_id'));
    }
}
