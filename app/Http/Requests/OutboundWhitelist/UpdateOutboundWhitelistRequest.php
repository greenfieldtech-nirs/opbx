<?php

declare(strict_types=1);

namespace App\Http\Requests\OutboundWhitelist;

use App\Models\OutboundWhitelist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request validator for updating an outbound whitelist entry.
 */
class UpdateOutboundWhitelistRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        // Only Owner and PBX Admin can update outbound whitelist entries
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $outboundWhitelist = $this->route('outbound_whitelist');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'destination_country' => [
                'required',
                'string',
                'max:100',
                // Country must be unique within the organization, excluding current entry
                Rule::unique('outbound_whitelists', 'destination_country')
                    ->where(function ($query) use ($user) {
                        return $query->where('organization_id', $user->organization_id);
                    })
                    ->ignore($outboundWhitelist->id),
            ],
            'destination_prefix' => [
                'nullable',
                'string',
                'max:12',
                'regex:/^[0-9+\-\s]+$/',
            ],
            'outbound_trunk_name' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'name.max' => 'Name must not exceed 255 characters.',
            'destination_country.required' => 'Country Code is required.',
            'destination_country.max' => 'Country Code must not exceed 100 characters.',
            'destination_country.unique' => 'An outbound whitelist entry for this Country Code already exists in your organization.',
            'destination_prefix.max' => 'Additional Prefix must not exceed 12 characters.',
            'destination_prefix.regex' => 'Additional Prefix must contain only numbers, spaces, plus signs, and hyphens.',
            'outbound_trunk_name.required' => 'Voice Trunk is required.',
            'outbound_trunk_name.max' => 'Voice Trunk must not exceed 255 characters.',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Trim and normalize name
        if ($this->has('name')) {
            $this->merge([
                'name' => trim($this->input('name')),
            ]);
        }

        // Normalize destination_prefix by removing extra spaces
        if ($this->has('destination_prefix')) {
            $prefix = $this->input('destination_prefix');
            $this->merge([
                'destination_prefix' => trim(preg_replace('/\s+/', ' ', $prefix)),
            ]);
        }

        // Trim and normalize outbound_trunk_name
        if ($this->has('outbound_trunk_name')) {
            $this->merge([
                'outbound_trunk_name' => trim($this->input('outbound_trunk_name')),
            ]);
        }

        // Trim and normalize destination_country
        if ($this->has('destination_country')) {
            $this->merge([
                'destination_country' => trim($this->input('destination_country')),
            ]);
        }
    }
}