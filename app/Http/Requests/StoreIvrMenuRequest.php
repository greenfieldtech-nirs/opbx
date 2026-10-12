<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\BusinessHours;
use App\Models\CallQueue;
use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Recording;
use App\Models\RingGroup;
use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array<mixed>|string>
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
                    } elseif (! is_string($value) && ! is_int($value) && $value !== null) {
                        $fail('The audio file path must be a string URL or a recording ID.');
                    }

                    // If it's an integer, validate it exists as a recording
                    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                        $recordingId = (int) $value;
                        $exists = Recording::where('id', $recordingId)->where('organization_id', $this->user()?->organization_id)->exists();
                        if (! $exists) {
                            $fail('The selected recording does not exist.');
                        }
                    }
                },
            ],
            'recording_id' => [
                'nullable',
                'integer',
                Rule::exists('recordings', 'id')->where('organization_id', $this->user()?->organization_id),
            ],
            'tts_text' => 'nullable|string|max:1000',
            'tts_voice' => 'nullable|string|max:50',
            'max_timeout' => 'required|integer|min:1|max:30',
            'inter_digit_timeout' => 'required|integer|min:1|max:30',
            'max_turns' => 'required|integer|min:1|max:9',
            'failover_destination_type' => 'required|string|in:extension,ring_group,conference_room,ivr_menu,ai_assistant,ai_load_balancer,business_hours,call_queue,hangup,unavailable',
            // Self-references (failover or an option back to this menu) are allowed: repeat-menu
            // loops are bounded by max_turns at runtime (owner decision, 2026-10-11).
            'failover_destination_id' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) {
                    if ($value && ! in_array($this->input('failover_destination_type'), ['hangup', 'unavailable'], true)) {
                        if (! $this->destinationExists($this->input('failover_destination_type'), $value)) {
                            $fail('The selected failover destination does not exist.');
                        }
                    }
                },
            ],
            'status' => 'required|string|in:active,inactive',
            'options' => 'required|array|min:1|max:20',
            'options.*.input_digits' => 'required|string|max:10',
            'options.*.description' => 'nullable|string|max:255',
            'options.*.destination_type' => 'required|string|in:extension,ring_group,conference_room,ivr_menu,ai_assistant,ai_load_balancer,business_hours,call_queue,unavailable',
            'options.*.destination_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    // Extract the index from the attribute (e.g., "options.0.destination_id" -> 0)
                    preg_match('/options\.(\d+)\.destination_id/', $attribute, $matches);
                    if (isset($matches[1])) {
                        $index = (int) $matches[1];
                        $options = $this->input('options', []);
                        if (isset($options[$index]['destination_type'])) {
                            $destinationType = $options[$index]['destination_type'];

                            // 'unavailable' = provisioned for future use: no destination to check.
                            if ($destinationType === 'unavailable') {
                                return;
                            }

                            // For all destination types, destination_id should be an integer (model ID)
                            if (! is_int($value) && ! ctype_digit((string) $value)) {
                                $fail('Destination ID must be a valid integer.');

                                return;
                            }
                            $value = (int) $value;

                            if (! $this->destinationExists($destinationType, $value)) {
                                $fail('The selected destination does not exist.');
                            }
                        }
                    }
                },
            ],
            'options.*.priority' => 'required|integer|min:1|max:20',
        ];
    }

    /** An 'unavailable' option has no target; the NOT NULL destination_id column gets 0. */
    protected function prepareForValidation(): void
    {
        $options = $this->input('options');
        if (! is_array($options)) {
            return;
        }
        foreach ($options as $i => $option) {
            if (is_array($option) && ($option['destination_type'] ?? null) === 'unavailable' && empty($option['destination_id'])) {
                $options[$i]['destination_id'] = 0;
            }
        }
        $this->merge(['options' => $options]);
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
            if (! $recordingId && ! $audioFilePath && ! $ttsText) {
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
        if (! $user) {
            return false;
        }

        $organizationId = $user->organization_id;

        $model = match ($type) {
            'extension' => Extension::class,
            'ring_group' => RingGroup::class,
            'conference_room' => ConferenceRoom::class,
            'ivr_menu' => IvrMenu::class,
            'ai_assistant' => AiAssistant::class,
            'ai_load_balancer' => AiAssistantLoadBalancer::class,
            'business_hours' => BusinessHours::class,
            'call_queue' => CallQueue::class,
            default => null,
        };

        return $model !== null
            && $model::where('id', (int) $id)->where('organization_id', $organizationId)->exists();
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
