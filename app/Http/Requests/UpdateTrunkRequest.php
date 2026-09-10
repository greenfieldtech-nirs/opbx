<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTrunkRequest extends FormRequest
{
    /**
     * IPv4 (covered by the hostname pattern), IPv6, or hostname.
     */
    private const HOST_REGEX = '/^(([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]*[a-zA-Z0-9])\.)*([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]*[a-zA-Z0-9])$|^([0-9a-fA-F]{0,4}:){2,7}[0-9a-fA-F]{0,4}$/';

    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isOwner() || $user->isPBXAdmin());
    }

    /**
     * name and direction are immutable post-create (Cloudonix PUT cannot
     * rename; direction changes on in-use trunks are confusing), so they
     * are intentionally absent here and stripped from validated input.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'ip' => ['sometimes', 'string', 'max:255', 'regex:'.self::HOST_REGEX],
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'transport' => ['sometimes', 'in:udp,tcp,tls'],
            'prefix' => ['sometimes', 'nullable', 'string', 'max:20'],
            // No 'sometimes' on username: required_with:password must fire
            // even when username is absent from the payload. Username alone
            // is allowed (keeps the stored password in Cloudonix).
            'username' => ['nullable', 'string', 'max:128', 'required_with:password'],
            'password' => ['sometimes', 'nullable', 'string', 'max:128'],
            'overwrite_from' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ip.regex' => 'The ip must be a valid IPv4 address, IPv6 address, or hostname.',
            'username.required_with' => 'A username is required when a password is provided.',
        ];
    }
}
