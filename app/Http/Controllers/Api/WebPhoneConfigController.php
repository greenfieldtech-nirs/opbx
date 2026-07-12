<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class WebPhoneConfigController extends Controller
{
    use ApiRequestHandler;

    public function config(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! in_array($user->role, [UserRole::OWNER, UserRole::SUPERVISOR], true)) {
            return response()->json([
                'message' => 'Web Phone is not available for this role.',
            ], 403);
        }

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

        $organization = $user->organization;
        $country = 'us';
        if ($organization && $organization->settings) {
            $settingsCountry = strtolower(trim((string) ($organization->settings['country'] ?? '')));
            if ($settingsCountry !== '') {
                $country = $settingsCountry;
            }
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
            'data' => [
                'sip_username' => $extension->extension_number,
                'sip_password' => $extension->password,
                'sip_domain' => $cloudonixSettings->domain_name,
                'sip_uri' => "sip:{$extension->extension_number}@{$cloudonixSettings->domain_name}",
                'display_name' => $user->name,
                'wss_server' => 'wss://webrtc.cloudonix.io',
                'websocket_port' => 443,
                'server_path' => '',
                'sip_contact' => $extension->extension_number,
                'profile_name' => $user->name,
                'registration_mode' => 'Direct',
                'country' => $country,
            ],
        ]);
    }
}
