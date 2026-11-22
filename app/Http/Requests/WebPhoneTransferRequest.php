<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WebPhoneTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // The SIP Call-ID of the Web Phone's leg, as JsSIP reports it.
            'call_id' => ['required', 'string', 'max:255'],
            // An extension or a phone number: digits with an optional leading '+'. Excludes the
            // '*' feature codes and coaching sentinels the routing engine also understands.
            'destination' => ['required', 'string', 'regex:/^\+?[0-9]{1,20}$/'],
        ];
    }
}
