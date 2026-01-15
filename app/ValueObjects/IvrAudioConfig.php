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
    ) {}

    /**
     * Create IvrAudioConfig from request data.
     * Handles recording resolution and audio configuration.
     *
     * @param array $data Request data
     * @param User|null $user Current user for recording resolution
     * @return self
     */
    public static function fromRequest(array $data, ?User $user): self
    {
        // Priority 1: recording_id
        if ($recordingId = $data['recording_id'] ?? null) {
            $audioFilePath = self::resolveRecordingUrl($recordingId, $user);
            return new self($audioFilePath, null, null);
        }

        // Priority 2: audio_file_path (could be URL or recording ID)
        if ($audioPath = $data['audio_file_path'] ?? null) {
            if (self::looksLikeRecordingId($audioPath)) {
                $audioFilePath = self::resolveRecordingUrl((int) $audioPath, $user);
                return new self($audioFilePath, null, null);
            }
            return new self($audioPath, null, null);
        }

        // Priority 3: TTS configuration
        if ($ttsText = $data['tts_text'] ?? null) {
            return new self(null, $ttsText, $data['tts_voice'] ?? null);
        }

        // No audio configuration
        return new self(null, null, null);
    }

    /**
     * Resolve a recording ID to its playback URL.
     *
     * @param int|string $recordingId
     * @param User|null $user
     * @return string|null
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
     * @param mixed $value
     * @return bool
     */
    private static function looksLikeRecordingId($value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    /**
     * Convert to array for database storage.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'audio_file_path' => $this->audioFilePath,
            'tts_text' => $this->ttsText,
            'tts_voice' => $this->ttsVoice,
        ];
    }
}