<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Enums\ExtensionType;
use App\Enums\UserStatus;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use App\Services\VoiceRouting\Strategies\RingGroupRoutingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RingGroupRoundRobinTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private RingGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_base_url' => 'https://example.com',
        ]);
        $this->group = RingGroup::factory()->roundRobin()->create([
            'organization_id' => $this->organization->id,
            'ring_turns' => 1,
        ]);
        foreach (['2001', '2002', '2003'] as $i => $number) {
            $ext = Extension::create([
                'organization_id' => $this->organization->id,
                'extension_number' => $number,
                'type' => ExtensionType::USER,
                'status' => UserStatus::ACTIVE,
                'configuration' => [],
            ]);
            RingGroupMember::create(['ring_group_id' => $this->group->id, 'extension_id' => $ext->id, 'priority' => $i + 1]);
        }
        Redis::del("ringgroup:rr:{$this->group->id}");
    }

    private function route(array $input = []): string
    {
        $request = Request::create('/voice/route', 'POST', $input + ['_organization_id' => $this->organization->id]);

        return app(RingGroupRoutingStrategy::class)
            ->route($request, new DidNumber, ['ring_group' => $this->group])
            ->getContent();
    }

    public function test_consecutive_calls_start_at_successive_members(): void
    {
        $first = [];
        foreach (range(1, 4) as $_) {
            $xml = $this->route();
            preg_match('/<Service[^>]*>(\d{4})<|>(\d{4})</', $xml, $m);
            $first[] = $m[1] !== '' ? $m[1] : $m[2];
        }

        $this->assertSame(['2001', '2002', '2003', '2001'], $first);
    }

    public function test_retry_walks_on_from_the_chosen_start_and_keeps_offset_in_callback(): void
    {
        $xml = $this->route();
        $this->assertStringContainsString('2001', $xml);
        $this->assertStringContainsString('rr_start=0', $xml);

        $this->route(); // second call starts at 2002
        $xml = $this->route(); // third call starts at 2003 (rr_start=2)
        $this->assertStringContainsString('rr_start=2', $xml);

        // The retry after the 3rd call's first attempt must be 2001 (offset 2 + attempt 1)
        $retry = $this->route(['attempt_number' => 1, 'rr_start' => 2]);
        $this->assertStringContainsString('2001', $retry);
        $this->assertStringNotContainsString('2003', $retry);
    }
}
