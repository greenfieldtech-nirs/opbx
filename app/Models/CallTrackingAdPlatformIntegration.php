<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([OrganizationScope::class])]
class CallTrackingAdPlatformIntegration extends Model
{
    use HasFactory;

    protected $table = 'call_tracking_ad_platform_integrations';

    protected $fillable = [
        'organization_id',
        'google_ads_enabled',
        'google_ads_developer_token',
        'google_ads_refresh_token',
        'google_ads_customer_id',
        'google_ads_conversion_action_resource_name',
        'meta_enabled',
        'meta_pixel_id',
        'meta_access_token',
    ];

    protected function casts(): array
    {
        return [
            'google_ads_enabled' => 'boolean',
            'meta_enabled' => 'boolean',
            'google_ads_developer_token' => 'encrypted',
            'google_ads_refresh_token' => 'encrypted',
            'google_ads_customer_id' => 'encrypted',
            'google_ads_conversion_action_resource_name' => 'encrypted',
            'meta_pixel_id' => 'encrypted',
            'meta_access_token' => 'encrypted',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
