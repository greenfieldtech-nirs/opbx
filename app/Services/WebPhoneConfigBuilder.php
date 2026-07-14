<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExtensionType;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\User;
use App\Scopes\OrganizationScope;

final class WebPhoneConfigBuilder
{
    /**
     * Build the JsSIP config array for a user's USER-type extension.
     * Returns null if the user has no extension or the org has no Cloudonix domain.
     *
     * Scope is bypassed so this works in both authenticated (SPA) and
     * tokenless (embed middleware) contexts; queries are constrained to the
     * user's own organization_id.
     *
     * @return array<string, mixed>|null
     */
    public function buildForUser(User $user): ?array
    {
        return OrganizationScope::bypass(function () use ($user) {
            $extension = Extension::where('user_id', $user->id)
                ->where('type', ExtensionType::USER)
                ->where('organization_id', $user->organization_id)
                ->first();

            if (! $extension) {
                return null;
            }

            $cloudonixSettings = CloudonixSettings::where('organization_id', $user->organization_id)->first();
            if (! $cloudonixSettings || ! $cloudonixSettings->domain_name) {
                return null;
            }

            $organization = $user->organization;
            $country = 'us';
            if ($organization && $organization->settings) {
                $settingsCountry = strtolower(trim((string) ($organization->settings['country'] ?? '')));
                if ($settingsCountry !== '') {
                    $country = $settingsCountry;
                }
            }

            return [
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
            ];
        });
    }
}
