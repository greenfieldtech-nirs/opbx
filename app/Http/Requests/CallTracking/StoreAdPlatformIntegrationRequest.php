<?php

declare(strict_types=1);

namespace App\Http\Requests\CallTracking;

use App\Models\CallTrackingAdPlatformIntegration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdPlatformIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'update',
            [CallTrackingAdPlatformIntegration::class, $this->user()?->organization]
        ) ?? false;
    }

    public function rules(): array
    {
        $existing = CallTrackingAdPlatformIntegration::forOrganization((int) $this->user()?->organization_id)->first();

        return [
            'google_ads_enabled' => ['required', 'boolean'],
            'google_ads_developer_token' => [
                'nullable',
                Rule::requiredIf(fn () => $this->boolean('google_ads_enabled') && $existing?->google_ads_developer_token === null),
                'string',
                'max:1024',
            ],
            'google_ads_refresh_token' => [
                'nullable',
                Rule::requiredIf(fn () => $this->boolean('google_ads_enabled') && $existing?->google_ads_refresh_token === null),
                'string',
                'max:8192',
            ],
            'google_ads_customer_id' => [
                'nullable',
                Rule::requiredIf(fn () => $this->boolean('google_ads_enabled') && $existing?->google_ads_customer_id === null),
                'string',
                'max:255',
            ],
            'google_ads_conversion_action_resource_name' => [
                'nullable',
                Rule::requiredIf(fn () => $this->boolean('google_ads_enabled') && $existing?->google_ads_conversion_action_resource_name === null),
                'string',
                'max:1024',
            ],
            'meta_enabled' => ['required', 'boolean'],
            'meta_pixel_id' => [
                'nullable',
                Rule::requiredIf(fn () => $this->boolean('meta_enabled') && $existing?->meta_pixel_id === null),
                'string',
                'max:255',
            ],
            'meta_access_token' => [
                'nullable',
                Rule::requiredIf(fn () => $this->boolean('meta_enabled') && $existing?->meta_access_token === null),
                'string',
                'max:8192',
            ],
        ];
    }
}
