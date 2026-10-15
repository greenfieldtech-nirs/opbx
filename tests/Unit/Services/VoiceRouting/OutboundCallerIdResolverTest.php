<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VoiceRouting;

use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Services\VoiceRouting\OutboundCallerIdResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutboundCallerIdResolverTest extends TestCase
{
    use RefreshDatabase;

    private OutboundCallerIdResolver $resolver;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new OutboundCallerIdResolver;
        $this->organization = Organization::factory()->create();
    }

    private function extension(?int $callerDidId = null): Extension
    {
        return Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'default_caller_id_did_id' => $callerDidId,
        ]);
    }

    private function did(string $number, string $status = 'active'): DidNumber
    {
        return DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'phone_number' => $number,
            'routing_type' => 'ring_group',
            'routing_config' => ['ring_group_id' => 1],
            'status' => $status,
        ]);
    }

    private function whitelist(?int $callerDidId = null): OutboundWhitelist
    {
        return OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'default_caller_id_did_id' => $callerDidId,
        ]);
    }

    public function test_uses_extension_caller_id_when_set(): void
    {
        $extDid = $this->did('+15551110000');
        $wlDid = $this->did('+15552220000');
        $extension = $this->extension($extDid->id);
        $whitelist = $this->whitelist($wlDid->id);

        $result = $this->resolver->resolve($extension, $whitelist, $this->organization->id);

        $this->assertSame('+15551110000', $result['callerId']);
        $this->assertNull($result['callerName']);
    }

    public function test_falls_back_to_whitelist_caller_id_when_extension_has_none(): void
    {
        $wlDid = $this->did('+15552220000');
        $extension = $this->extension(null);
        $whitelist = $this->whitelist($wlDid->id);

        $result = $this->resolver->resolve($extension, $whitelist, $this->organization->id);

        $this->assertSame('+15552220000', $result['callerId']);
        $this->assertNull($result['callerName']);
    }

    public function test_returns_zeros_when_neither_extension_nor_whitelist_set(): void
    {
        $extension = $this->extension(null);
        $whitelist = $this->whitelist(null);

        $result = $this->resolver->resolve($extension, $whitelist, $this->organization->id);

        $this->assertSame('Unknown', $result['callerId']);
        $this->assertNull($result['callerName']);
    }

    public function test_ignores_inactive_extension_did_and_uses_whitelist(): void
    {
        $extDid = $this->did('+15551110000', 'inactive');
        $wlDid = $this->did('+15552220000');
        $extension = $this->extension($extDid->id);
        $whitelist = $this->whitelist($wlDid->id);

        $result = $this->resolver->resolve($extension, $whitelist, $this->organization->id);

        $this->assertSame('+15552220000', $result['callerId']);
    }

    public function test_ignores_inactive_whitelist_did_and_returns_zeros(): void
    {
        $wlDid = $this->did('+15552220000', 'inactive');
        $extension = $this->extension(null);
        $whitelist = $this->whitelist($wlDid->id);

        $result = $this->resolver->resolve($extension, $whitelist, $this->organization->id);

        $this->assertSame('Unknown', $result['callerId']);
    }

    public function test_ignores_did_from_another_org(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignDid = DidNumber::factory()->create([
            'organization_id' => $otherOrg->id,
            'phone_number' => '+15554443333',
            'routing_type' => 'ring_group',
            'routing_config' => ['ring_group_id' => 7],
            'status' => 'active',
        ]);
        $extension = $this->extension($foreignDid->id);
        $whitelist = $this->whitelist(null);

        $result = $this->resolver->resolve($extension, $whitelist, $this->organization->id);

        $this->assertSame('Unknown', $result['callerId']);
    }

    private function setOrgDefault(?int $didId): void
    {
        $this->organization->update(['settings' => ['default_outbound_caller_id_did_id' => $didId]]);
    }

    public function test_falls_back_to_org_default_when_extension_and_whitelist_have_none(): void
    {
        $orgDid = $this->did('+15553330000');
        $this->setOrgDefault($orgDid->id);

        $result = $this->resolver->resolve($this->extension(null), $this->whitelist(null), $this->organization->id);

        $this->assertSame('+15553330000', $result['callerId']);
        $this->assertNull($result['callerName']);
    }

    public function test_whitelist_caller_id_wins_over_org_default(): void
    {
        $wlDid = $this->did('+15552220000');
        $this->setOrgDefault($this->did('+15553330000')->id);

        $result = $this->resolver->resolve($this->extension(null), $this->whitelist($wlDid->id), $this->organization->id);

        $this->assertSame('+15552220000', $result['callerId']);
    }

    public function test_extension_caller_id_wins_over_org_default(): void
    {
        $extDid = $this->did('+15551110000');
        $this->setOrgDefault($this->did('+15553330000')->id);

        $result = $this->resolver->resolve($this->extension($extDid->id), $this->whitelist(null), $this->organization->id);

        $this->assertSame('+15551110000', $result['callerId']);
    }

    public function test_ignores_inactive_org_default_did(): void
    {
        $this->setOrgDefault($this->did('+15553330000', 'inactive')->id);

        $result = $this->resolver->resolve($this->extension(null), $this->whitelist(null), $this->organization->id);

        $this->assertSame('Unknown', $result['callerId']);
    }

    public function test_ignores_org_default_did_from_another_org(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreignDid = DidNumber::factory()->create([
            'organization_id' => $otherOrg->id,
            'phone_number' => '+15554443333',
            'routing_type' => 'ring_group',
            'routing_config' => ['ring_group_id' => 7],
            'status' => 'active',
        ]);
        $this->setOrgDefault($foreignDid->id);

        $result = $this->resolver->resolve($this->extension(null), $this->whitelist(null), $this->organization->id);

        $this->assertSame('Unknown', $result['callerId']);
    }
}
