<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CloudonixClient;

use App\Models\CloudonixSettings;
use App\Services\Cloudonix\CloudonixVoiceService;
use App\Services\CloudonixClient\CloudonixApiException;
use App\Services\CloudonixClient\CloudonixCallsClient;
use App\Services\CloudonixClient\CloudonixDomainsClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cloudonix.api.base_url' => 'https://cx.test']);
        Cache::flush();
    }

    private function settings(): CloudonixSettings
    {
        return new CloudonixSettings(['domain_uuid' => 'dom-1', 'domain_api_key' => 'XI-test-key']);
    }

    public function test_get_is_retried_after_a_503(): void
    {
        Http::fake(['cx.test/*' => Http::sequence()
            ->push('down', 503)
            ->push(['id' => 'c1'], 200),
        ]);

        $result = (new CloudonixCallsClient($this->settings()))->getCallStatus('c1');

        $this->assertSame(['id' => 'c1'], $result);
        Http::assertSentCount(2);
    }

    public function test_get_is_not_retried_after_a_404(): void
    {
        Http::fake(['cx.test/*' => Http::response('nope', 404)]);

        (new CloudonixCallsClient($this->settings()))->getCallStatus('c1');

        Http::assertSentCount(1);
    }

    public function test_post_is_not_retried_after_a_503(): void
    {
        Http::fake(['cx.test/*' => Http::sequence()
            ->push('down', 503)
            ->push(['token' => 't'], 200),
        ]);

        $result = (new CloudonixCallsClient($this->settings()))->originateApplicationCall(['destination' => '+1']);

        $this->assertNull($result);
        Http::assertSentCount(1);
    }

    public function test_validate_domain_credentials_uses_the_base_client(): void
    {
        Http::fake(['cx.test/customers/self/domains/dom-1' => Http::response(['domain' => 'a.b'], 200)]);

        $result = CloudonixDomainsClient::validateDomainCredentials('dom-1', 'XI-test-key');

        $this->assertTrue($result['valid']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer XI-test-key'));
    }

    public function test_voice_service_maps_auth_failure_by_status(): void
    {
        Http::fake(['cx.test/*' => Http::response('{"message":"bad"}', 401)]);

        try {
            app(CloudonixVoiceService::class)->getVoices($this->settings(), 'req-1');
            $this->fail('expected exception');
        } catch (CloudonixApiException $e) {
            $this->assertTrue($e->isAuthFailure());
            $this->assertSame(401, $e->status);
        }
    }

    public function test_voice_service_maps_connection_failure(): void
    {
        Http::fake(['cx.test/*' => fn () => throw new ConnectionException('timeout')]);

        try {
            app(CloudonixVoiceService::class)->getVoices($this->settings(), 'req-2');
            $this->fail('expected exception');
        } catch (CloudonixApiException $e) {
            $this->assertTrue($e->isConnectionFailure());
        }
    }
}
