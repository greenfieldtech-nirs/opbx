<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Models\Recording;
use App\Models\User;

/**
 * Value object for IVR audio configuration.
 * Handles resolution of recordings, audio paths, and TTS configuration.
 */
class IvrAudioConfig
{
    private function __construct(
        public readonly ?string $audioFilePath,
        public readonly ?string $ttsText,
        public readonly ?string $ttsVoice,
        public readonly ?int $recordingId,
    ) {}

    /**
     * Create IvrAudioConfig from request data.
     * Handles recording resolution and audio configuration.
     *
     * @param  array  $data  Request data
     * @param  User|null  $user  Current user for recording resolution
     */
    public static function fromRequest(array $data, ?User $user): self
    {
        // Priority 1: recording_id
        if ($recordingId = $data['recording_id'] ?? null) {
            $audioFilePath = self::resolveRecordingUrl($recordingId, $user);

            return new self($audioFilePath, null, null, (int) $recordingId);
        }

        // Priority 2: audio_file_path (could be URL or recording ID)
        if ($audioPath = $data['audio_file_path'] ?? null) {
            if (self::looksLikeRecordingId($audioPath)) {
                $audioFilePath = self::resolveRecordingUrl((int) $audioPath, $user);

                return new self($audioFilePath, null, null, (int) $audioPath);
            }

            return new self($audioPath, null, null, null);
        }

        // Priority 3: TTS configuration
        if ($ttsText = $data['tts_text'] ?? null) {
            return new self(null, $ttsText, $data['tts_voice'] ?? null, null);
        }

        // No audio configuration
        return new self(null, null, null, null);
    }

    /**
     * Resolve a recording ID to its playback URL.
     *
     * @param  int|string  $recordingId
     */
    private static function resolveRecordingUrl($recordingId, ?User $user): ?string
    {
        $recording = Recording::find($recordingId);

        if ($recording && $recording->isActive() && $user) {
            return $recording->getPlaybackUrl($user->id);
        }

        return null;
    }

    /**
     * Check if a value looks like a recording ID (numeric string or int).
     *
     * @param  mixed  $value
     */
    private static function looksLikeRecordingId($value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    /**
     * Convert to array for database storage.
     */
    public function toArray(): array
    {
        return [
            'audio_file_path' => $this->audioFilePath,
            'tts_text' => $this->ttsText,
            'tts_voice' => $this->ttsVoice,
            'recording_id' => $this->recordingId,
        ];
    }
}
