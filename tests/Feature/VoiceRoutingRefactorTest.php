<?php

namespace Tests\Feature;

use App\Enums\ExtensionType;
use App\Models\DidNumber;
use App\Models\Organization;
use App\Services\VoiceRouting\Strategies\RoutingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Mocks\VoiceRouting\MockRoutingStrategy;
use Tests\TestCase;

class VoiceRoutingRefactorTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;
    protected DidNumber $did;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => 'active']);
        $this->did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
            'routing_type' => 'extension', // Default
        ]);
    }

    /** @test */
    public function it_can_resolve_mock_strategy()
    {
        $strategy = new MockRoutingStrategy();
        $this->assertTrue($strategy->canHandle(ExtensionType::USER));

        $response = $strategy->route(new Request(), $this->did, []);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('<Say>Mock Strategy Executed</Say>', $response->getContent());
    }

    /** @test */
    public function it_has_routing_sentry_tables()
    {
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_01_02_200852_create_routing_sentry_tables'
        ]);
    }
}
