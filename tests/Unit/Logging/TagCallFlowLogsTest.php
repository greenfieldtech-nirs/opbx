<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Http\Middleware\TagCallFlowLogs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
}
