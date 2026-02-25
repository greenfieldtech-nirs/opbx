<?php

declare(strict_types=1);

namespace App\Http\Requests\InboundBlacklist;

use App\Enums\InboundBlacklistMatchType;
use App\Enums\InboundBlacklistRejectionStrategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInboundBlacklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'caller_id_pattern' => [
                'required',
                'string',
                'max:50',
                'regex:/^[\d\+\*\?]+$/', // E.164 format or wildcards
            ],
            'match_type' => ['required', Rule::enum(InboundBlacklistMatchType::class)],
            'rejection_strategy' => ['required', Rule::enum(InboundBlacklistRejectionStrategy::class)],
            'did_number_ids' => [
                'nullable',
                'array',
                'required_if:is_global,false',
            ],
            'did_number_ids.*' => [
                'integer',
                'exists:did_numbers,id',
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
            'did_number_ids.required_if' => 'Please select at least one phone number when not using global scope.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // If did_number_ids is provided, is_global should be false
        if ($this->has('did_number_ids') && ! empty($this->input('did_number_ids'))) {
            $this->merge(['is_global' => false]);
        }
    }
}
