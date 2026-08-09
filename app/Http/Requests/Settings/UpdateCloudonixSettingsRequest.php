<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for updating Cloudonix integration settings.
 */
class UpdateCloudonixSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Only organization owners can update Cloudonix settings.
     */
    public function authorize(): bool
    {
        return $this->user()?->role->canManageOrganization() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'domain_uuid' => ['nullable', 'string', 'uuid'],
            'domain_name' => ['nullable', 'string', 'max:255'],
            'domain_api_key' => ['nullable', 'string', 'max:255'],
            'domain_requests_api_key' => ['nullable', 'string', 'max:255'],
            'webhook_base_url' => ['nullable', 'string', 'url', 'max:255'],
            'embed_allowed_domains' => ['sometimes', 'array'],
            // Accept a bare hostname (crm.acme.com), localhost, or an IPv4
            // address, each with an optional :port for local dev origins
            // (e.g. localhost:3000, 127.0.0.1:3000). Scheme is stripped client-side.
            'embed_allowed_domains.*' => ['string', 'regex:/^(?:(?=.{1,253}(?::|$))(?:[a-z0-9](?:-*[a-z0-9])*)(?:\.[a-z0-9](?:-*[a-z0-9])*)+|localhost|(?:\d{1,3})(?:\.\d{1,3}){3})(?::\d{1,5})?$/i'],
            'no_answer_timeout' => ['required', 'integer', 'min:5', 'max:120'],
            'recording_format' => ['required', 'string', 'in:wav,mp3'],
            'cloudonix_package' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom attribute names for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'domain_uuid' => 'domain UUID',
            'domain_name' => 'domain name',
            'domain_api_key' => 'domain API key',
            'domain_requests_api_key' => 'webhook API key',
            'webhook_base_url' => 'webhook base URL',
            'embed_allowed_domains' => 'embed allowed domains',
            'no_answer_timeout' => 'no answer timeout',
            'recording_format' => 'recording format',
            'cloudonix_package' => 'Cloudonix package',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain_uuid.uuid' => 'The domain UUID must be a valid UUID format.',
            'no_answer_timeout.min' => 'The no answer timeout must be at least 5 seconds.',
            'no_answer_timeout.max' => 'The no answer timeout must not exceed 120 seconds.',
            'recording_format.in' => 'The recording format must be either wav or mp3.',
            'embed_allowed_domains.*.regex' => 'Each embed allowed domain must be a valid hostname, localhost, or IP address, optionally with a port (e.g. crm.acme.com, localhost:3000, 127.0.0.1:3000).',
        ];
    }
}
