<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ExtensionType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Models\CallDetailRecord;
use App\Models\Extension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebPhoneCallsLogController extends Controller
{
    use ApiRequestHandler;

    public function index(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        $extension = Extension::where('user_id', $user->id)
            ->where('type', ExtensionType::USER)
            ->where('organization_id', $user->organization_id)
            ->first();

        if (! $extension) {
            return response()->json([
                'message' => 'No extension is assigned to this user.',
            ], 404);
        }

        $records = CallDetailRecord::forOrganization($user->organization_id)
            ->where('from', $extension->extension_number)
            ->where('to', 'not like', 'spy\_%')
            ->where('to', 'not like', 'barge\_%')
            ->where('to', 'not like', 'whisper\_%')
            ->orderByDesc('session_timestamp')
            ->limit(50)
            ->get();

        $data = $records->map(fn (CallDetailRecord $cdr) => [
            'to' => $cdr->to,
            'session_timestamp' => $cdr->session_timestamp?->toIso8601String(),
            'duration' => (int) $cdr->duration,
            'duration_formatted' => $cdr->formatted_duration,
            'disposition' => $cdr->disposition,
        ])->all();

        return response()->json(['data' => $data]);
    }
}
