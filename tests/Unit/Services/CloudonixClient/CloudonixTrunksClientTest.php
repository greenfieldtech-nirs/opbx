<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CloudonixClient;

use App\Models\CloudonixSettings;
use App\Services\CloudonixClient\CloudonixTrunksClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudonixTrunksClientTest extends TestCase
{
    private const DOMAIN_UUID = 'test-domain-uuid-123';

    private string $trunksUrl;

    private CloudonixTrunksClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trunksUrl = rtrim((string) config('cloudonix.api.base_url'), '/')
            .'/customers/self/domains/'.self::DOMAIN_UUID.'/trunks';

        $settings = new CloudonixSettings([
            'organization_id' => 1,
            'domain_uuid' => self::DOMAIN_UUID,
            'domain_api_key' => 'test-api-key',
            'domain_name' => 'test.cloudonix.io',
        ]);

        $this->client = new CloudonixTrunksClient($settings);
    }

    public function test_create_trunk_posts_to_trunks_endpoint(): void
    {
        Http::fake([
            $this->trunksUrl => Http::response(['id' => 42, 'name' => 'My Trunk'], 201),
        ]);

        $payload = ['name' => 'My Trunk', 'direction' => 'outbound'];
        $result = $this->client->createTrunk($payload);

        $this->assertSame(['id' => 42, 'name' => 'My Trunk'], $result);

        Http::assertSent(function ($request) use ($payload) {
            return $request->method() === 'POST'
                && $request->url() === $this->trunksUrl
                && $request->data() === $payload;
        });
    }

    public function test_get_trunk_returns_object(): void
    {
        $trunk = ['id' => 7, 'name' => 'Trunk Seven'];

        Http::fake([
            $this->trunksUrl.'/7' => Http::response($trunk),
        ]);

        $this->assertSame($trunk, $this->client->getTrunk(7));

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request->url() === $this->trunksUrl.'/7';
        });
    }

    public function test_update_trunk_puts_fields(): void
    {
        Http::fake([
            $this->trunksUrl.'/7' => Http::response(['id' => 7, 'name' => 'Renamed']),
        ]);

        $result = $this->client->updateTrunk('7', ['name' => 'Renamed']);

        $this->assertSame(['id' => 7, 'name' => 'Renamed'], $result);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->url() === $this->trunksUrl.'/7'
                && $request->data() === ['name' => 'Renamed'];
        });
    }

    public function test_delete_trunk_returns_true_on_204(): void
    {
        Http::fake([
            $this->trunksUrl.'/7' => Http::response(null, 204),
        ]);

        $this->assertTrue($this->client->deleteTrunk(7));

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && $request->url() === $this->trunksUrl.'/7';
        });
    }

    public function test_mutations_invalidate_trunk_caches(): void
    {
        Http::fake([
            $this->trunksUrl => Http::response([['id' => 1, 'direction' => 'outbound']]),
            $this->trunksUrl.'/1' => Http::response(['id' => 1, 'name' => 'Updated']),
        ]);

        $trunksKey = 'cloudonix:trunks:'.self::DOMAIN_UUID;
        $outboundKey = 'cloudonix:outbound_trunks:'.self::DOMAIN_UUID;

        // Prime both caches
        $this->client->listTrunks();
        $this->client->listOutboundTrunks();

        $this->assertTrue(Cache::has($trunksKey));
        $this->assertTrue(Cache::has($outboundKey));

        $this->client->updateTrunk(1, ['name' => 'Updated']);

        $this->assertFalse(Cache::has($trunksKey));
        $this->assertFalse(Cache::has($outboundKey));
    }
}
