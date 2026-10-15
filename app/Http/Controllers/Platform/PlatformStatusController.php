<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformHealthService;
use Illuminate\Http\JsonResponse;

/**
 * Platform Status page: latest component health plus 24h history.
 */
class PlatformStatusController extends Controller
{
    public function __construct(private readonly PlatformHealthService $health) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->health->snapshot()]);
    }

    /** Run all checks now, store them, return the fresh snapshot. */
    public function check(): JsonResponse
    {
        $this->health->runAndStore();

        return response()->json(['data' => $this->health->snapshot()]);
    }
}
