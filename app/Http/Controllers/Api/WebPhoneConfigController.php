<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ExtensionType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Services\WebPhoneConfigBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class WebPhoneConfigController extends Controller
{
    use ApiRequestHandler;

    public function config(Request $request, WebPhoneConfigBuilder $builder): JsonResponse
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

        $cloudonixSettings = CloudonixSettings::where('organization_id', $user->organization_id)->first();

        if (! $cloudonixSettings || ! $cloudonixSettings->domain_name) {
            return response()->json([
                'message' => 'Cloudonix settings are not configured for this organization.',
            ], 404);
        }

        Log::info('Web Phone config retrieved', [
            'request_id' => $this->getRequestId(),
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
            'security_event' => true,
        ]);

        return response()->json([
            'data' => $builder->buildForUser($user),
        ]);
    }
}
