<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIvrMenuRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'audio_file_path' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    // Accept either a string URL or an integer recording ID
                    if (is_string($value) && strlen($value) > 500) {
                        $fail('The audio file path may not be greater than 500 characters.');
                    } elseif (!is_string($value) && !is_int($value) && $value !== null) {
                        $fail('The audio file path must be a string URL or a recording ID.');
                    }

                    // If it's an integer, validate it exists as a recording
                    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                        $recordingId = (int) $value;
                        $exists = \App\Models\Recording::where('id', $recordingId)->exists();
                        if (!$exists) {
                            $fail('The selected recording does not exist.');
                        }
                    }
                },
            ],
            'recording_id' => 'nullable|integer|exists:recordings,id',
            'tts_text' => 'nullable|string|max:1000',
            'tts_voice' => 'nullable|string|max:50',
            'max_turns' => 'required|integer|min:1|max:9',
            'failover_destination_type' => 'required|string|in:extension,ring_group,conference_room,ivr_menu,hangup',
            'failover_destination_id' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) {
                    if ($value && $this->input('failover_destination_type') !== 'hangup') {
                        if (!$this->destinationExists($this->input('failover_destination_type'), $value)) {
                            $fail("The selected failover destination does not exist.");
                        }
                    }
                },
            ],
            'status' => 'required|string|in:active,inactive',
            'options' => 'required|array|min:1|max:20',
            'options.*.input_digits' => 'required|string|max:10',
            'options.*.description' => 'nullable|string|max:255',
            'options.*.destination_type' => 'required|string|in:extension,ring_group,conference_room,ivr_menu',
            'options.*.destination_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    // Extract the index from the attribute (e.g., "options.0.destination_id" -> 0)
                    preg_match('/options\.(\d+)\.destination_id/', $attribute, $matches);
                    if (!empty($matches[1])) {
                        $index = (int) $matches[1];
                        $options = $this->input('options', []);
                        if (isset($options[$index]['destination_type'])) {
                            $destinationType = $options[$index]['destination_type'];

                            // Validate data type based on destination type
                            if ($destinationType === 'extension') {
                                // For extensions, destination_id should be a string (extension number)
                                if (!is_string($value) && !is_numeric($value)) {
                                    $fail("Extension destination must be a valid extension number.");
                                    return;
                                }
                            } else {
                                // For other types, destination_id should be an integer (model ID)
                                if (!is_int($value) && !ctype_digit((string) $value)) {
                                    $fail("Destination ID must be a valid integer.");
                                    return;
                                }
                                $value = (int) $value;
                            }

                            if (!$this->destinationExists($destinationType, $value)) {
                                $fail("The selected destination does not exist.");
                            }
                        }
                    }
                },
            ],
            'options.*.priority' => 'required|integer|min:1|max:20',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $recordingId = $this->input('recording_id');
            $audioFilePath = $this->input('audio_file_path');
            $ttsText = $this->input('tts_text');

            // Cannot have both recording_id and tts_text
            if ($recordingId && $ttsText) {
                $validator->errors()->add(
                    'recording_id',
                    'Cannot specify both a recording and Text-to-Speech text. Please choose one audio source.'
                );
            }

            // Cannot have both recording_id and audio_file_path (direct URL)
            if ($recordingId && $audioFilePath) {
                $validator->errors()->add(
                    'recording_id',
                    'Cannot specify both a recording and a direct audio URL. Please choose one audio source.'
                );
            }

            // Cannot have both direct audio_file_path and tts_text
            if ($audioFilePath && $ttsText) {
                $validator->errors()->add(
                    'audio_file_path',
                    'Cannot specify both a direct audio URL and Text-to-Speech text. Please choose one audio source.'
                );
            }

            // Must have at least one audio source
            if (!$recordingId && !$audioFilePath && !$ttsText) {
                $validator->errors()->add(
                    'audio_configuration',
                    'An IVR menu must have either a recording, direct audio URL, or Text-to-Speech text configured.'
                );
            }
        });
    }

    /**
     * Check if a destination exists and belongs to the same organization.
     */
    protected function destinationExists(string $type, string|int $id): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        $organizationId = $user->organization_id;

        return match ($type) {
            'extension' => Extension::where('extension_number', (string) $id)
                ->where('organization_id', $organizationId)
                ->exists(),
            'ring_group' => RingGroup::where('id', (int) $id)
                ->where('organization_id', $organizationId)
                ->exists(),
            'conference_room' => ConferenceRoom::where('id', (int) $id)
                ->where('organization_id', $organizationId)
                ->exists(),
            'ivr_menu' => IvrMenu::where('id', (int) $id)
                ->where('organization_id', $organizationId)
                ->exists(),
            default => false,
        };
    }

    /**
     * Get custom error messages.
     */
    public function messages(): array
    {
        return [
            'options.required' => 'At least one menu option is required.',
            'options.min' => 'At least one menu option is required.',
            'options.max' => 'Maximum 20 menu options are allowed.',
            'options.*.input_digits.required' => 'Input digits are required for each option.',
            'options.*.destination_type.required' => 'Destination type is required for each option.',
            'options.*.destination_id.required' => 'Destination is required for each option.',
            'options.*.priority.required' => 'Priority is required for each option.',
        ];
    }
}
