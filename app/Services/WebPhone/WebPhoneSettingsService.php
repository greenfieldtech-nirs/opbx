<?php

declare(strict_types=1);

namespace App\Services\WebPhone;

use App\Enums\UserStatus;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Services\CloudonixClient\CloudonixClient;
use Illuminate\Support\Facades\Log;

/**
 * Web Phone settings, stored in the extension's Cloudonix subscriber profile.
 *
 * Cloudonix replaces the whole profile on update (verified 2026-11-22), so every write
 * re-sends the current profile with our keys merged in; other keys are kept. An update
 * without a profile (the extension sync) leaves the profile alone.
 */
class WebPhoneSettingsService
{
    /** setting => [profile key, default] */
    public const SETTINGS = [
        'auto_answer' => ['webphone-auto-answer', false],
        'keep_alive' => ['webphone-keep-alive', true],
        'refresh_on_hangup' => ['webphone-refresh-on-hangup', true],
    ];

    /** @return array<string, bool> */
    public static function defaults(): array
    {
        return array_map(fn (array $s) => $s[1], self::SETTINGS);
    }

    /**
     * Settings for the phone to apply. Missing keys are written with their defaults.
     * If Cloudonix cannot be reached, the defaults are returned and nothing is written:
     * the phone must still start.
     *
     * @return array<string, bool>
     */
    public function load(Extension $extension, CloudonixSettings $cloudonix): array
    {
        $profile = $this->profile($extension, $cloudonix);
        if ($profile === null) {
            return self::defaults();
        }

        $settings = [];
        $missing = false;
        foreach (self::SETTINGS as $name => [$key, $default]) {
            $missing = $missing || ! array_key_exists($key, $profile);
            $settings[$name] = array_key_exists($key, $profile) ? $this->bool($profile[$key], $default) : $default;
        }

        if ($missing) {
            $this->write($extension, $cloudonix, $profile, $settings);
        }

        return $settings;
    }

    /**
     * Store the settings; returns them, or null when Cloudonix could not be read or updated.
     *
     * @param  array<string, bool>  $settings
     * @return array<string, bool>|null
     */
    public function save(Extension $extension, CloudonixSettings $cloudonix, array $settings): ?array
    {
        $profile = $this->profile($extension, $cloudonix);
        if ($profile === null) {
            return null;
        }

        $settings = array_merge(self::defaults(), array_intersect_key($settings, self::SETTINGS));

        return $this->write($extension, $cloudonix, $profile, $settings) ? $settings : null;
    }

    /** @return array<string, mixed>|null the current profile, or null when it cannot be read */
    private function profile(Extension $extension, CloudonixSettings $cloudonix): ?array
    {
        if (! $extension->cloudonix_subscriber_id) {
            return null;
        }

        try {
            // The client throws when the org has no API key or domain UUID; the phone must still start.
            $subscriber = (new CloudonixClient($cloudonix))->getSubscriber((string) $extension->cloudonix_subscriber_id);
        } catch (\Throwable $e) {
            Log::warning('Web Phone settings could not be read from Cloudonix', [
                'extension_id' => $extension->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_array($subscriber)) {
            return null;
        }

        return is_array($subscriber['profile'] ?? null) ? $subscriber['profile'] : [];
    }

    private function write(Extension $extension, CloudonixSettings $cloudonix, array $profile, array $settings): bool
    {
        foreach (self::SETTINGS as $name => [$key]) {
            $profile[$key] = $settings[$name] ? 'true' : 'false';
        }

        // Same fields as the extension sync (CloudonixSubscriberService), plus the profile: the shape verified against Cloudonix.
        $updated = (new CloudonixClient($cloudonix))->updateSubscriber((string) $extension->cloudonix_subscriber_id, [
            'msisdn' => $extension->extension_number,
            'sipPassword' => $extension->password,
            'active' => $extension->status === UserStatus::ACTIVE,
            'profile' => $profile,
        ]);
        if ($updated === null) {
            Log::warning('Web Phone settings could not be stored in the Cloudonix subscriber profile', [
                'extension_id' => $extension->id,
                'subscriber_id' => $extension->cloudonix_subscriber_id,
            ]);
        }

        return $updated !== null;
    }

    private function bool(mixed $value, bool $default): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
