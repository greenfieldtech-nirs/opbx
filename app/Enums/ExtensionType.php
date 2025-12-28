<?php

declare(strict_types=1);

namespace App\Enums;

enum ExtensionType: string
{
    case USER = 'user';
    case CONFERENCE = 'conference';
    case RING_GROUP = 'ring_group';
    case IVR = 'ivr';
    case AI_ASSISTANT = 'ai_assistant';
    case CUSTOM_LOGIC = 'custom_logic';
    case FORWARD = 'forward';

    /**
     * Get human-readable label for the extension type.
     *
     * @return string
     */
    public function label(): string
    {
        return match($this) {
            self::USER => 'User Extension',
            self::CONFERENCE => 'Conference Room',
            self::RING_GROUP => 'Ring Group',
            self::IVR => 'IVR Menu',
            self::AI_ASSISTANT => 'AI Assistant',
            self::CUSTOM_LOGIC => 'Custom Logic',
            self::FORWARD => 'Call Forward',
        };
    }

    /**
     * Get description for the extension type.
     *
     * @return string
     */
    public function description(): string
    {
        return match($this) {
            self::USER => 'Direct extension for a user',
            self::CONFERENCE => 'Conference room for multiple participants',
            self::RING_GROUP => 'Ring multiple extensions simultaneously or sequentially',
            self::IVR => 'Interactive voice response menu',
            self::AI_ASSISTANT => 'AI-powered virtual assistant',
            self::CUSTOM_LOGIC => 'Custom call routing logic',
            self::FORWARD => 'Forward calls to external number',
        };
    }

    /**
     * Check if this extension type requires a user assignment.
     *
     * @return bool
     */
    public function requiresUser(): bool
    {
        return $this === self::USER;
    }

    /**
     * Check if this extension type supports voicemail.
     *
     * @return bool
     */
    public function supportsVoicemail(): bool
    {
        return $this === self::USER;
    }

    /**
     * Check if this extension type can make outbound calls.
     *
     * Only PBX User extensions are allowed to make outbound calls by default.
     * Other extension types (AI Assistant, IVR, etc.) cannot make outbound calls.
     *
     * @return bool
     */
    public function canMakeOutboundCalls(): bool
    {
        return $this === self::USER;
    }

    /**
     * Get required configuration fields for this extension type.
     *
     * @return array<string>
     */
    public function requiredConfigFields(): array
    {
        return match($this) {
            self::USER => [],
            self::CONFERENCE => ['conference_room_id'],
            self::RING_GROUP => ['ring_group_id'],
            self::IVR => ['ivr_id'],
            self::AI_ASSISTANT => ['provider', 'phone_number'],
            self::CUSTOM_LOGIC => ['custom_logic_id'],
            self::FORWARD => ['forward_to'],
        };
    }

    /**
     * Get all extension types as an array.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }
}
