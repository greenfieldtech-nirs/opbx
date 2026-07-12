<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CoachTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Role/scope enforced in the controller.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'policy' => ['required', Rule::in(['spy', 'whisper', 'barge'])],
            'whisper_party' => [
                Rule::requiredIf(fn () => $this->input('policy') === 'whisper'),
                Rule::in(['caller', 'callee', 'both']),
            ],
        ];
    }
}
