<?php

declare(strict_types=1);

namespace App\Http\Requests\InboundBlacklist;

use App\Enums\InboundBlacklistMatchType;
use App\Enums\InboundBlacklistRejectionStrategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateInboundBlacklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'caller_id_pattern' => [
                'sometimes',
                'string',
                'max:50',
                'regex:/^[\d\+\*\?]+$/', // E.164 format or wildcards
            ],
            'match_type' => ['sometimes', Rule::enum(InboundBlacklistMatchType::class)],
            'rejection_strategy' => ['sometimes', Rule::enum(InboundBlacklistRejectionStrategy::class)],
            // At least one number when the rule is not global: see after()
            'did_number_ids' => ['nullable', 'array'],
            'did_number_ids.*' => [
                'integer',
                Rule::exists('did_numbers', 'id')->where('organization_id', $this->user()?->organization_id),
            ],
            'is_global' => ['boolean'],
            // Note: torment_room_prefix and torment_music_timeout are auto-generated
            // Room prefix: random 16-char string, Timeout: fixed at 600 seconds
        ];
    }

    public function messages(): array
    {
        return [
            'caller_id_pattern.regex' => 'The caller ID pattern must be a valid phone number or pattern (digits, +, *, ? only).',
        ];
    }

    /**
     * The resulting rule (sent values, else the stored ones) must be global or have at least one phone number.
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $entry = $this->route('inbound_blacklist');
            $isGlobal = $this->has('is_global') ? $this->boolean('is_global') : (bool) $entry?->is_global;
            $hasNumbers = $this->has('did_number_ids')
                ? ! empty($this->input('did_number_ids'))
                : (bool) $entry?->didNumbers()->exists();

            if (! $isGlobal && ! $hasNumbers) {
                $validator->errors()->add('did_number_ids', StoreInboundBlacklistRequest::NUMBERS_REQUIRED);
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        // If did_number_ids is provided, is_global should be false
        if ($this->has('did_number_ids') && ! empty($this->input('did_number_ids'))) {
            $this->merge(['is_global' => false]);
        }
    }
}
