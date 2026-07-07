<?php

declare(strict_types=1);

namespace App\Enums;

enum CallTrackingDestinationType: string
{
    case FORWARD = 'forward';
    case EXTENSION = 'extension';
    case RING_GROUP = 'ring_group';
    case BUSINESS_HOURS = 'business_hours';
    case CONFERENCE_ROOM = 'conference_room';
    case IVR_MENU = 'ivr_menu';
    case AI_ASSISTANT = 'ai_assistant';
    case AI_LOAD_BALANCER = 'ai_load_balancer';

    public function label(): string
    {
        return match ($this) {
            self::FORWARD => 'Forward to External Number',
            self::EXTENSION => 'Extension',
            self::RING_GROUP => 'Ring Group',
            self::BUSINESS_HOURS => 'Business Hours',
            self::CONFERENCE_ROOM => 'Conference Room',
            self::IVR_MENU => 'IVR Menu',
            self::AI_ASSISTANT => 'AI Assistant',
            self::AI_LOAD_BALANCER => 'AI Load Balancer',
        };
    }

    public function toExtensionType(): ?ExtensionType
    {
        return match ($this) {
            self::FORWARD => ExtensionType::FORWARD,
            self::EXTENSION => ExtensionType::USER,
            self::RING_GROUP => ExtensionType::RING_GROUP,
            self::CONFERENCE_ROOM => ExtensionType::CONFERENCE,
            self::IVR_MENU => ExtensionType::IVR,
            self::AI_ASSISTANT => ExtensionType::AI_ASSISTANT,
            self::AI_LOAD_BALANCER => ExtensionType::AI_LOAD_BALANCER,
            self::BUSINESS_HOURS => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(self::cases(), function (array $carry, self $case): array {
            $carry[$case->value] = $case->label();

            return $carry;
        }, []);
    }

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
