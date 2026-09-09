<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTrunkRequest extends FormRequest
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
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:64'],
            'ip' => ['required', 'string', 'max:255', 'regex:'.self::HOST_REGEX],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'transport' => ['required', 'in:udp,tcp,tls'],
            // public-inbound / public-outbound are Cloudonix-managed and not creatable
            'direction' => ['required', 'in:inbound,outbound'],
            'prefix' => ['nullable', 'string', 'max:20'],
            'username' => ['nullable', 'string', 'max:128'],
            'password' => ['nullable', 'string', 'max:128', 'required_with:username'],
            'overwrite_from' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ip.regex' => 'The ip must be a valid IPv4 address, IPv6 address, or hostname.',
            'password.required_with' => 'A password is required when a username is provided.',
        ];
    }
}
