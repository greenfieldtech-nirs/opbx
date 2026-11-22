<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiMonitor\AiMonitorRequest;
use App\Models\AiAssistant;
use App\Services\AiMonitor\AiMonitorStats;
use Illuminate\Http\JsonResponse;

/** AI Real Time Monitor: live figures for the organisation's AI assistants and load balancers. */
class AiMonitorController extends Controller
{
    public function __invoke(AiMonitorRequest $request, AiMonitorStats $stats): JsonResponse
    {
        $this->authorize('monitor', AiAssistant::class);

        return response()->json(['data' => $stats->snapshot($request->minutes())]);
    }
}
