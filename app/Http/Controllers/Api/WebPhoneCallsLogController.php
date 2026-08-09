<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ExtensionType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Models\Extension;
use App\Services\WebPhoneCallsLogBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebPhoneCallsLogController extends Controller
{
    use ApiRequestHandler;

    public function index(Request $request, WebPhoneCallsLogBuilder $builder): JsonResponse
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

        return response()->json(['data' => $builder->buildForUser($user)]);
    }
}
