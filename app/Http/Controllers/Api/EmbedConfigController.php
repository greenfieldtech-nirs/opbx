<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WebPhoneTransferRequest;
use App\Services\VoiceRouting\CallTransferService;
use App\Services\WebPhoneCallsLogBuilder;
use App\Services\WebPhoneConfigBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmbedConfigController extends Controller
{
    public function config(Request $request, WebPhoneConfigBuilder $builder): JsonResponse
    {
        // Config carries SIP credentials. Cross-origin callers send an Origin, which the
        // middleware checks against the allowlist. The widget iframe is served by OPBX and
        // calls this same-origin, where browsers send no Origin: accept a missing Origin
        // only when the browser marks the request same-origin (owner decision D4).
        if (! $this->fromWidget($request)) {
            return response()->json(['message' => 'Origin required.'], 403);
        }

        $user = $request->attributes->get('embedUser');
        $config = $builder->buildForUser($user);

        if ($config === null) {
            return response()->json(['message' => 'Embedded dialer configuration is unavailable.'], 404);
        }

        // The embed snippet's own switch decides whether the widget offers call transfer.
        $config['transfer_enabled'] = (bool) $request->attributes->get('embedToken')->allow_transfer;

        return response()->json(['data' => $config]);
    }

    public function callsLog(Request $request, WebPhoneCallsLogBuilder $builder): JsonResponse
    {
        $user = $request->attributes->get('embedUser');

        return response()->json(['data' => $builder->buildForUser($user)]);
    }

    public function transfer(WebPhoneTransferRequest $request, CallTransferService $transfers): JsonResponse
    {
        if (! $this->fromWidget($request)) {
            return response()->json(['message' => 'Origin required.'], 403);
        }

        if (! $request->attributes->get('embedToken')->allow_transfer) {
            return response()->json(['message' => 'Call transfer is not enabled for this embedded dialer.'], 403);
        }

        return WebPhoneTransferController::performTransfer($request->attributes->get('embedUser'), $request->validated(), $transfers);
    }

    public function directory(Request $request): JsonResponse
    {
        if (! $this->fromWidget($request)) {
            return response()->json(['message' => 'Origin required.'], 403);
        }

        if (! $request->attributes->get('embedToken')->allow_transfer) {
            return response()->json(['message' => 'Call transfer is not enabled for this embedded dialer.'], 403);
        }

        return response()->json(['data' => WebPhoneTransferController::directoryFor($request->attributes->get('embedUser'))]);
    }

    /**
     * A browser request from the widget: cross-origin callers send an Origin (which the middleware
     * checked against the allowlist); the OPBX-served iframe calls same-origin, where browsers send
     * no Origin but mark the request same-origin. Bare token requests (curl) are refused.
     */
    private function fromWidget(Request $request): bool
    {
        return (bool) $request->headers->get('Origin') || $request->headers->get('Sec-Fetch-Site') === 'same-origin';
    }
}
