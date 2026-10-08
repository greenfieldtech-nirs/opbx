<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Http\Middleware\TagCallFlowLogs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TagCallFlowLogsTest extends TestCase
{
    public function test_shares_call_flow_log_type_for_the_request(): void
    {
        Log::shouldReceive('shareContext')
            ->once()
            ->with(['log_type' => 'call_flow']);

        $response = (new TagCallFlowLogs)->handle(Request::create('/x', 'POST'), fn () => response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    /**
     * Every call_flow line must carry the session token so the aggregation
     * stack can prefix it. The middleware resolves it once for the request.
     *
     * @dataProvider tokenSources
     */
    #[DataProvider('tokenSources')]
    public function test_shares_session_token_from_all_known_payload_shapes(array $payload, string $expected): void
    {
        Log::shouldReceive('shareContext')
            ->once()
            ->with(['log_type' => 'call_flow', 'session_token' => $expected]);

        $response = (new TagCallFlowLogs)->handle(Request::create('/x', 'POST', $payload), fn () => response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    public static function tokenSources(): array
    {
        return [
            'voice webhook (Session)' => [['Session' => 'sess-voice'], 'sess-voice'],
            'status webhook (token)' => [['token' => 'sess-status'], 'sess-status'],
            'cdr webhook (session.token)' => [['session' => ['token' => 'sess-cdr']], 'sess-cdr'],
            'misc (session_token)' => [['session_token' => 'sess-misc'], 'sess-misc'],
            'queue callback (session_data.call_id)' => [['session_data' => '{"call_id":"sess-queue","call_queue_id":7}'], 'sess-queue'],
        ];
    }
}
