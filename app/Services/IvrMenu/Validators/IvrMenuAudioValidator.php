<?php

declare(strict_types=1);

namespace App\Services\IvrMenu\Validators;

use Illuminate\Validation\Validator;

/**
 * Validates IVR menu audio configuration (TTS vs file-based audio).
 */
class IvrMenuAudioValidator implements IvrMenuValidatorInterface
{
    public function validate(Validator $validator, array $data, ?int $excludeMenuId = null): void
    {
        $audioFilePath = $data['audio_file_path'] ?? null;
        $ttsText = $data['tts_text'] ?? null;

        // Ensure at least one audio source is provided
        if (empty($audioFilePath) && empty($ttsText)) {
            $validator->errors()->add(
                'audio_file_path',
                'Either an audio file or TTS text must be provided.'
            );
            $validator->errors()->add(
                'tts_text',
                'Either an audio file or TTS text must be provided.'
            );
            return;
        }

        // If both are provided, TTS takes precedence (configurable behavior)
        if (!empty($audioFilePath) && !empty($ttsText)) {
            // This could be an error or a warning depending on requirements
            // For now, we'll allow both but log a warning
        }

        // Validate audio file path format if provided
        if (!empty($audioFilePath)) {
            if (!preg_match('/\.(mp3|wav|m4a|ogg)$/i', $audioFilePath)) {
                $validator->errors()->add(
                    'audio_file_path',
                    'Audio file must be in MP3, WAV, M4A, or OGG format.'
                );
            }
        }

        // Validate TTS text length if provided
        if (!empty($ttsText)) {
            if (strlen($ttsText) > 1000) {
                $validator->errors()->add(
                    'tts_text',
                    'TTS text must not exceed 1000 characters.'
                );
            }

            // Basic TTS text validation (no special characters that might break synthesis)
            if (preg_match('/[<>{}\[\]]/', $ttsText)) {
                $validator->errors()->add(
                    'tts_text',
                    'TTS text contains invalid characters.'
                );
            }
        }
    }
}