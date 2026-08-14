<?php

declare(strict_types=1);

namespace App\Services\CallRecording;

/**
 * Result of a recording decision, ready to splat into CxmlBuilder's Dial calls.
 */
final class CallRecordingDecision
{
    public function __construct(
        public readonly ?string $record = null,
        public readonly ?string $recordingStatusCallback = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
