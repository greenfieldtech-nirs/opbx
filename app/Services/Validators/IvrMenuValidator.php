<?php

declare(strict_types=1);

namespace App\Services\Validators;

use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\IvrMenuOption;
use App\Models\RingGroup;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as IlluminateValidator;

/**
 * Validator class for IVR menu data.
 * Extracts validation logic from IvrMenuService for better testability.
 */
class IvrMenuValidator
{
    /**
     * Validate IVR menu data including options.
     *
     * @param array $data
     * @param int|null $excludeMenuId Menu ID to exclude from unique validation
     * @return array Validated data
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validate(array $data, ?int $excludeMenuId = null): array
    {
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'audio_file_path' => 'nullable|string|max:500',
            'tts_text' => 'nullable|string|max:1000',
            'max_turns' => 'required|integer|min:1|max:9',
            'failover_destination_type' => ['required', Rule::in(['extension', 'ring_group', 'conference_room', 'ivr_menu', 'hangup'])],
            'failover_destination_id' => [
                Rule::requiredIf(function () use ($data) {
                    return (isset($data['failover_destination_type']) ? $data['failover_destination_type'] : null) !== 'hangup';
                }),
                'nullable',
                'integer'
            ],
            'status' => 'required|string|in:active,inactive',
            'options' => 'required|array|min:1|max:20',
            'options.*.input_digits' => [
                'required',
                'string',
                'max:10',
                'regex:/^[0-9*#]+$/',
                // Custom rule to ensure unique digits within the menu
                function ($attribute, $value, $fail) use ($data, $excludeMenuId) {
                    $this->validateUniqueDigits($value, isset($data['options']) ? $data['options'] : [], $attribute, $fail, $excludeMenuId);
                },
            ],
            'options.*.description' => 'nullable|string|max:255',
            'options.*.destination_id' => 'required|integer',
            'options.*.priority' => 'required|integer|min:1|max:20',
        ]);

        $validator->after(function ($validator) use ($data) {
            $this->validateDestinations($validator, $data);
            $this->validatePriorities($validator, $data);
            $this->validateAudioConfiguration($validator, $data);
            $this->validateAudioSourceConsistency($validator, $data);
        });

        return $validator->validate();
    }

    /**
     * Validate that destinations exist and are valid.
     */
    private function validateDestinations(IlluminateValidator $validator, array $data): void
    {
        $organizationId = auth()->user() ? auth()->user()->organization_id : null;
        if (!$organizationId) {
            return;
        }

        foreach (isset($data['options']) ? $data['options'] : [] as $index => $option) {
            $destinationType = isset($option['destination_type']) ? $option['destination_type'] : null;
            $destinationId = isset($option['destination_id']) ? $option['destination_id'] : null;

            if (!$destinationType || !$destinationId) {
                continue;
            }

            $exists = match ($destinationType) {
                'extension' => Extension::where('id', $destinationId)
                    ->where('organization_id', $organizationId)
                    ->where('status', 'active')
                    ->exists(),
                'ring_group' => RingGroup::where('id', $destinationId)
                    ->where('organization_id', $organizationId)
                    ->where('status', 'active')
                    ->exists(),
                'conference_room' => ConferenceRoom::where('id', $destinationId)
                    ->where('organization_id', $organizationId)
                    ->exists(),
                'ivr_menu' => IvrMenu::where('id', $destinationId)
                    ->where('organization_id', $organizationId)
                    ->where('status', 'active')
                    ->when(isset($data['id']), fn($q) => $q->where('id', '!=', $data['id']))
                    ->exists(),
                default => false,
            };

            if (!$exists) {
                $validator->errors()->add(
                    "options.{$index}.destination_id",
                    "Selected destination does not exist or is not accessible."
                );
            }
        }

        // Validate failover destination
        if (isset($data['failover_destination_type']) && isset($data['failover_destination_id'])) {
            $failoverType = $data['failover_destination_type'];
            $failoverId = $data['failover_destination_id'];

            if ($failoverType !== 'hangup') {
                $exists = match ($failoverType) {
                    'extension' => Extension::where('id', $failoverId)
                        ->where('organization_id', $organizationId)
                        ->where('status', 'active')
                        ->exists(),
                    'ring_group' => RingGroup::where('id', $failoverId)
                        ->where('organization_id', $organizationId)
                        ->where('status', 'active')
                        ->exists(),
                    'conference_room' => ConferenceRoom::where('id', $failoverId)
                        ->where('organization_id', $organizationId)
                        ->exists(),
                    'ivr_menu' => IvrMenu::where('id', $failoverId)
                        ->where('organization_id', $organizationId)
                        ->where('status', 'active')
                        ->when(isset($data['id']), fn($q) => $q->where('id', '!=', $data['id']))
                        ->exists(),
                    default => false,
                };

                if (!$exists) {
                    $validator->errors()->add(
                        'failover_destination_id',
                        'Selected failover destination does not exist or is not accessible.'
                    );
                }
            }
        }
    }

    /**
     * Validate that priorities are unique within the menu.
     */
    private function validatePriorities(IlluminateValidator $validator, array $data): void
    {
        $priorities = [];
        foreach (isset($data['options']) ? $data['options'] : [] as $index => $option) {
            $priority = isset($option['priority']) ? $option['priority'] : null;
            if ($priority !== null) {
                if (in_array($priority, $priorities)) {
                    $validator->errors()->add(
                        "options.{$index}.priority",
                        "Priority {$priority} is already used by another option."
                    );
                }
                $priorities[] = $priority;
            }
        }
    }

    /**
     * Validate that only one audio configuration is provided.
     */
    private function validateAudioConfiguration(IlluminateValidator $validator, array $data): void
    {
        $audioFilePath = isset($data['audio_file_path']) ? $data['audio_file_path'] : null;
        $ttsText = isset($data['tts_text']) ? $data['tts_text'] : null;

        if (!empty($audioFilePath) && !empty($ttsText)) {
            $validator->errors()->add(
                'audio_configuration',
                'An IVR menu can have either a static audio file OR Text-to-Speech text, not both. Please choose one audio method.'
            );
        }

        if (empty($audioFilePath) && empty($ttsText)) {
            $validator->errors()->add(
                'audio_configuration',
                'An IVR menu must have either a static audio file or Text-to-Speech text configured.'
            );
        }
    }

    /**
     * Validate that audio source parameters are consistent.
     */
    private function validateAudioSourceConsistency(IlluminateValidator $validator, array $data): void
    {
        $recordingId = isset($data['recording_id']) ? $data['recording_id'] : null;
        $audioFilePath = isset($data['audio_file_path']) ? $data['audio_file_path'] : null;
        $ttsText = isset($data['tts_text']) ? $data['tts_text'] : null;

        if ($recordingId && $ttsText) {
            $validator->errors()->add(
                'recording_id',
                'Cannot specify both a recording and Text-to-Speech text. Please choose one audio source.'
            );
        }

        if ($recordingId && $audioFilePath) {
            $validator->errors()->add(
                'recording_id',
                'Cannot specify both a recording and a direct audio URL. Please choose one audio source.'
            );
        }

        if ($audioFilePath && $ttsText) {
            $validator->errors()->add(
                'audio_file_path',
                'Cannot specify both a direct audio URL and Text-to-Speech text. Please choose one audio source.'
            );
        }
    }

    /**
     * Validate that input digits are unique within the menu.
     */
    private function validateUniqueDigits(string $digits, array $options, string $attribute, callable $fail, ?int $excludeMenuId = null): void
    {
        $currentIndex = (int) str_replace(['options.', '.input_digits'], '', $attribute);

        foreach ($options as $index => $option) {
            if ($index === $currentIndex) {
                continue;
            }

            if ((isset($option['input_digits']) ? $option['input_digits'] : '') === $digits) {
                $fail("Input digits '{$digits}' are already used by another option in this menu.");
                return;
            }
        }

        if ($excludeMenuId) {
            $exists = IvrMenuOption::where('ivr_menu_id', $excludeMenuId)
                ->where('input_digits', $digits)
                ->exists();

            if ($exists) {
                $fail("Input digits '{$digits}' are already used by another option in this menu.");
            }
        }
    }
}
