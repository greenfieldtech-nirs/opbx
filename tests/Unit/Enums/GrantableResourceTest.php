<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\GrantableResource;
use Tests\TestCase;

class GrantableResourceTest extends TestCase
{
    public function test_slugs_returns_all_grantable_slugs(): void
    {
        $slugs = GrantableResource::slugs();
        $this->assertContains('business-hours', $slugs);
        $this->assertContains('extensions', $slugs);
        $this->assertContains('users', $slugs);
        $this->assertNotContains('settings', $slugs);
        $this->assertNotContains('auth', $slugs);
    }

    public function test_from_route_name_maps_action_route_to_slug(): void
    {
        $this->assertSame(
            GrantableResource::BUSINESS_HOURS,
            GrantableResource::fromRouteName('business-hours.index')
        );
        $this->assertSame(
            GrantableResource::BUSINESS_HOURS,
            GrantableResource::fromRouteName('business-hours.toggle-status')
        );
        $this->assertSame(
            GrantableResource::EXTENSIONS,
            GrantableResource::fromRouteName('extensions.sync.perform')
        );
    }

    public function test_from_route_name_returns_null_for_non_grantable(): void
    {
        $this->assertNull(GrantableResource::fromRouteName('settings.cloudonix.show'));
        $this->assertNull(GrantableResource::fromRouteName('profile.show'));
        $this->assertNull(GrantableResource::fromRouteName(null));
    }
}
