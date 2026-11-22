<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WebPhoneSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'auto_answer' => ['required', 'boolean'],
            'keep_alive' => ['required', 'boolean'],
            'refresh_on_hangup' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, bool> */
    public function settings(): array
    {
        return array_map('boolval', $this->validated());
    }
}
