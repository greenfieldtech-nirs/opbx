<?php

declare(strict_types=1);

namespace App\Http\Requests\EmbedToken;

use App\Enums\EmbedIconPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateEmbedTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level authorization is handled in the controller (actor role +
        // same-organization check). Allow the request to proceed to validation.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'icon_position' => ['sometimes', Rule::enum(EmbedIconPosition::class)],
            'icon_background_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
