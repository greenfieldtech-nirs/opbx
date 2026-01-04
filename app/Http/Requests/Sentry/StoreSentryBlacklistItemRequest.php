<?php

declare(strict_types=1);

namespace App\Http\Requests\Sentry;

use Illuminate\Foundation\Http\FormRequest;

class StoreSentryBlacklistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isOwner() || $this->user()->isPBXAdmin();
    }

    public function rules(): array
    {
        return [
            'phone_number' => 'required|string|max:20',
            'reason' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date|after:now',
        ];
    }
}
