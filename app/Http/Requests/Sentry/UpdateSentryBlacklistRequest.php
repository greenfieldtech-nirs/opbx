<?php

declare(strict_types=1);

namespace App\Http\Requests\Sentry;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSentryBlacklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isOwner() || $this->user()->isPBXAdmin();
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => 'sometimes|required|in:active,inactive',
        ];
    }
}
