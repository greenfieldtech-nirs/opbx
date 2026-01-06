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
            'audio_file_path' => 'nullable|string|max:500',
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
                'integer',
                function ($attribute, $value, $fail) {
                    // Extract the index from the attribute (e.g., "options.0.destination_id" -> 0)
                    preg_match('/options\.(\d+)\.destination_id/', $attribute, $matches);
                    if (!empty($matches[1])) {
                        $index = (int) $matches[1];
                        $options = $this->input('options', []);
                        if (isset($options[$index]['destination_type'])) {
                            $destinationType = $options[$index]['destination_type'];
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
     * Check if a destination exists and belongs to the same organization.
     */
    protected function destinationExists(string $type, string|int $id): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        // Cast to int for database query
        $id = (int) $id;
        $organizationId = $user->organization_id;

        return match ($type) {
            'extension' => Extension::where('id', $id)
                ->where('organization_id', $organizationId)
                ->exists(),
            'ring_group' => RingGroup::where('id', $id)
                ->where('organization_id', $organizationId)
                ->exists(),
            'conference_room' => ConferenceRoom::where('id', $id)
                ->where('organization_id', $organizationId)
                ->exists(),
            'ivr_menu' => IvrMenu::where('id', $id)
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
