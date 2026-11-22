<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ExtensionType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\WebPhoneSettingsRequest;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Services\WebPhone\WebPhoneSettingsService;
use Illuminate\Http\JsonResponse;

/** Saves the user's own Web Phone settings to their extension's Cloudonix subscriber profile. */
final class WebPhoneSettingsController extends Controller
{
    use ApiRequestHandler;

    public function update(WebPhoneSettingsRequest $request, WebPhoneSettingsService $service): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        $extension = Extension::where('user_id', $user->id)
            ->where('type', ExtensionType::USER)
            ->where('organization_id', $user->organization_id)
            ->first();

        if (! $extension) {
            return $this->errorResponse('No extension is assigned to this user.', 404);
        }

        $cloudonix = CloudonixSettings::forOrganization($user->organization_id);
        if (! $cloudonix || ! $cloudonix->domain_name) {
            return $this->errorResponse('Cloudonix settings are not configured for this organization.', 409, 'CLOUDONIX_NOT_CONFIGURED');
        }

        if (! $extension->cloudonix_subscriber_id) {
            return $this->errorResponse('This extension is not synced to Cloudonix yet, so its settings cannot be stored.', 409, 'EXTENSION_NOT_SYNCED');
        }

        $saved = $service->save($extension, $cloudonix, $request->settings());
        if ($saved === null) {
            return $this->errorResponse('Cloudonix did not accept the settings. Try again shortly.', 502, 'CLOUDONIX_UNAVAILABLE');
        }

        return response()->json(['data' => $saved]);
    }
}
