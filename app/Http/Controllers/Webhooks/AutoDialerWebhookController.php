<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles Auto Dialer webhooks from Cloudonix.
 */
class AutoDialerWebhookController extends Controller
{
    /**
     * Handle call status updates from Cloudonix.
     */
    public function callStatus(Request $request): JsonResponse
    {
        $sessionToken = $request->input('session_token');
        $callId = $request->input('call_id');
        $status = $request->input('status');

        Log::info('Received auto-dialer call status webhook', [
            'session_token' => $sessionToken,
            'call_id' => $callId,
            'status' => $status,
        ]);

        if (! $sessionToken) {
            return response()->json(['status' => 'error'], 400);
        }

        // Find the session
        $session = AutoDialerCallSession::where('session_token', $sessionToken)->first();

        if (! $session) {
            Log::warning('Auto-dialer session not found', [
                'session_token' => $sessionToken,
            ]);

            return response()->json(['status' => 'not_found'], 404);
        }

        // Update session status
        switch ($status) {
            case 'answered':
            case 'connected':
                $session->markAsAnswered();

                // Update destination
                $destination = AutoDialerDestination::find($session->destination_id);
                if ($destination) {
                    $destination->update([
                        'status' => 'connected',
                        'last_call_id' => $callId,
                    ]);
                }
                break;

            case 'completed':
                $session->markAsCompleted();
                break;

            case 'failed':
            case 'busy':
            case 'no-answer':
                $session->markAsFailed();
                break;
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle AMD (Answering Machine Detection) results.
     */
    public function amdResult(Request $request): JsonResponse
    {
        $sessionToken = $request->input('session_token');
        $result = $request->input('result'); // 'human', 'machine', 'unknown'
        $confidence = $request->input('confidence');

        Log::info('Received AMD result', [
            'session_token' => $sessionToken,
            'result' => $result,
            'confidence' => $confidence,
        ]);

        if (! $sessionToken) {
            return response()->json(['status' => 'error'], 400);
        }

        $session = AutoDialerCallSession::where('session_token', $sessionToken)->first();

        if ($session) {
            $session->setAmdResult($result, $confidence);
        }

        return response()->json(['status' => 'ok']);
    }
}
