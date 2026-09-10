<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\GrantableResource;
use Illuminate\Support\Facades\Route;
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

    public function test_credential_subroutes_are_excluded_even_under_granted_parents(): void
    {
        $this->assertNull(GrantableResource::fromRouteName('extensions.password'));
        $this->assertNull(GrantableResource::fromRouteName('extensions.reset-password'));
        $this->assertNull(GrantableResource::fromRouteName('users.embed-token.show'));
        $this->assertNull(GrantableResource::fromRouteName('users.embed-token.regenerate'));
        $this->assertNull(GrantableResource::fromRouteName('users.password.update'));
        // Siblings remain grantable.
        $this->assertSame(GrantableResource::EXTENSIONS, GrantableResource::fromRouteName('extensions.index'));
        $this->assertSame(GrantableResource::USERS, GrantableResource::fromRouteName('users.index'));
    }

    public function test_ai_assistant_provider_routes_map_to_the_provider_resource(): void
    {
        $this->assertSame(
            GrantableResource::AI_ASSISTANT_PROVIDERS,
            GrantableResource::fromRouteName('ai-assistant.providers.index')
        );
        // The assistants resource itself is untouched by the alias.
        $this->assertSame(
            GrantableResource::AI_ASSISTANTS,
            GrantableResource::fromRouteName('ai-assistants.index')
        );
    }

    public function test_every_grantable_slug_has_a_registered_route(): void
    {
        // ponytail: catches enum/route drift — the one thing that silently breaks scoping
        $routeNames = collect(Route::getRoutes())
            ->map(fn ($r) => $r->getName())
            ->filter()
            ->values();

        foreach (GrantableResource::routePrefixes() as $slug => $prefixes) {
            $matches = collect($prefixes)->contains(
                fn (string $prefix) => $routeNames->contains(
                    fn (string $name) => $name === $prefix || str_starts_with($name, $prefix.'.')
                )
            );

            $this->assertTrue($matches, "No route registered for grantable slug: {$slug}");
        }
    }
}
