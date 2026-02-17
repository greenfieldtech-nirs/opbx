<?php

declare(strict_types=1);

namespace App\Http\Requests\InboundBlacklist;

use App\Enums\InboundBlacklistMatchType;
use App\Enums\InboundBlacklistRejectionStrategy;
use App\Enums\UserStatus;
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
            'description' => ['nullable', 'string', 'max:255'],
            'rejection_strategy' => ['required', Rule::enum(InboundBlacklistRejectionStrategy::class)],
            'did_number_id' => [
                'nullable',
                'integer',
                'exists:did_numbers,id',
            ],
            'is_global' => ['boolean'],
            'torment_room_prefix' => [
                'nullable',
                'string',
                'max:20',
                'required_if:rejection_strategy,torment',
            ],
            'torment_music_timeout' => [
                'nullable',
                'integer',
                'min:60',
                'max:3600',
            ],
            'status' => [Rule::enum(UserStatus::class)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'caller_id_pattern.regex' => 'The caller ID pattern must be a valid phone number or pattern (digits, +, *, ? only).',
            'did_number_id.exists' => 'The selected phone number does not exist.',
            'torment_room_prefix.required_if' => 'A room prefix is required when using the Torment strategy.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // If did_number_id is provided, is_global should be false
        if ($this->has('did_number_id') && $this->input('did_number_id')) {
            $this->merge(['is_global' => false]);
        }
    }
}
