<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WebPhoneCallsLogBuilder;
use App\Services\WebPhoneConfigBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmbedConfigController extends Controller
{
    public function config(Request $request, WebPhoneConfigBuilder $builder): JsonResponse
    {
        $user = $request->attributes->get('embedUser');
        $config = $builder->buildForUser($user);

        if ($config === null) {
            return response()->json(['message' => 'Embedded dialer configuration is unavailable.'], 404);
        }

        return response()->json(['data' => $config]);
    }

    public function callsLog(Request $request, WebPhoneCallsLogBuilder $builder): JsonResponse
    {
        $user = $request->attributes->get('embedUser');

        return response()->json(['data' => $builder->buildForUser($user)]);
    }
}
