<?php

declare(strict_types=1);

namespace App\Http\Requests\Sentry;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSentrySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isOwner() || $this->user()->isPBXAdmin();
    }

    public function rules(): array
    {
        return [
            'velocity_limit' => 'required|integer|min:0',
            'volume_limit' => 'required|integer|min:0',
            'default_action' => 'required|in:allow,block,flag',
        ];
    }
}
