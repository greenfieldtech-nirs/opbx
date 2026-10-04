<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tags every log emitted while handling this request as call-flow.
 * Applied to voice routing and Cloudonix webhook route groups.
 */
class TagCallFlowLogs
{
    public function handle(Request $request, Closure $next): Response
    {
        Log::shareContext(['log_type' => 'call_flow']);

        return $next($request);
    }
}
