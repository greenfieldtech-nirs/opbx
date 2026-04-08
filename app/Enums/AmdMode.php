<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Answering Machine Detection Mode Enum
 *
 * Represents the AMD detection modes for auto-dialer campaigns.
 */
enum AmdMode: string
{
    case ENABLED = 'Enabled';
    case DETECT_MESSAGE_END = 'DetectMessageEnd';

    /**
     * Get display label for the mode.
     */
    public function label(): string
    {
        return match ($this) {
            self::ENABLED => 'Enabled',
            self::DETECT_MESSAGE_END => 'Detect Message End',
        };
    }

    /**
     * Get description for the mode.
     */
    public function description(): string
    {
        return match ($this) {
            self::ENABLED => 'Detect if call is answered by human or machine',
            self::DETECT_MESSAGE_END => 'Detect end of answering machine message',
        };
    }
}
