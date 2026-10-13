<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/** Public, unauthenticated platform status: six groups, 6h history, no internal details. */
class PublicStatusController extends Controller
{
    public function show(PlatformHealthService $health): JsonResponse
    {
        return response()->json([
            'data' => Cache::remember('public:status', 30, fn () => $health->publicSnapshot()),
        ]);
    }
}
