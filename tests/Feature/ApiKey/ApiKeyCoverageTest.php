<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKey;

use App\Enums\GrantableResource;
use App\Http\Middleware\EnforceApiKeyScope;
use App\Models\CloudonixSettings;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Verifies the API-key scope system covers the tenant control plane:
 * previously missing resources (IVR, providers, call tracking, supervisors,
 * campaigns, distribution lists, live calls, notifications) are grantable,
 * credential-bearing subroutes are excluded, and a coverage guard fails the
 * suite if a future route in a key-enforced group has no resource mapping.
 */
class ApiKeyCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function keyFor(array $permissions): string
    {
        $org = Organization::factory()->create();
        [, $plaintext] = app(ApiKeyService::class)->create(
            organizationId: $org->id, name: 'k', permissions: $permissions, createdBy: null,
        );

        return $plaintext;
    }

    public function test_ivr_menus_are_grantable(): void
    {
        $token = $this->keyFor([['resource' => 'ivr-menus', 'level' => 'read']]);

        $this->withToken($token)->getJson('/api/v1/ivr-menus')->assertStatus(200);
        // The voices endpoint requires org Cloudonix settings (503 without them);
        // anything but 403 proves the scope gate let the key through.
        $this->assertNotSame(403, $this->withToken($token)->getJson('/api/v1/ivr-menus/voices')->status());
        // Write requires a write grant.
        $this->withToken($token)->postJson('/api/v1/ivr-menus', [])->assertStatus(403);
    }

    public function test_ai_assistant_providers_use_their_own_grant(): void
    {
        $withProviders = $this->keyFor([['resource' => 'ai-assistant-providers', 'level' => 'read']]);
        $this->withToken($withProviders)->getJson('/api/v1/ai-assistant/providers')->assertStatus(200);

        // The ai-assistants grant must NOT implicitly cover the provider registry.
        $withoutProviders = $this->keyFor([['resource' => 'ai-assistants', 'level' => 'read']]);
        $this->withToken($withoutProviders)->getJson('/api/v1/ai-assistant/providers')->assertStatus(403);
    }

    public function test_call_tracking_resources_are_grantable(): void
    {
        $token = $this->keyFor([
            ['resource' => 'call-tracking-campaigns', 'level' => 'read'],
            ['resource' => 'call-tracking-analytics', 'level' => 'read'],
            ['resource' => 'call-tracking-sessions', 'level' => 'read'],
        ]);

        $this->withToken($token)->getJson('/api/v1/call-tracking-campaigns')->assertStatus(200);
        $this->withToken($token)->getJson('/api/v1/call-tracking-sessions')->assertStatus(200);
        // Analytics requires start/end dates — a 422 proves the request passed the scope gate.
        $this->assertNotSame(403, $this->withToken($token)->getJson('/api/v1/call-tracking-analytics')->status());
    }

    public function test_supervisor_assignments_are_grantable(): void
    {
        $token = $this->keyFor([['resource' => 'supervisors', 'level' => 'read']]);

        // 404 (no such user) proves the scope gate passed.
        $this->assertNotSame(403, $this->withToken($token)->getJson('/api/v1/supervisors/1/assignments')->status());
    }

    public function test_previously_sanctum_only_groups_now_accept_keys(): void
    {
        $token = $this->keyFor([
            ['resource' => 'session-updates', 'level' => 'read'],
            ['resource' => 'auto-dialer-campaigns', 'level' => 'read'],
            ['resource' => 'distribution-lists', 'level' => 'read'],
            ['resource' => 'call-notifications', 'level' => 'read'],
        ]);

        $this->withToken($token)->getJson('/api/v1/session-updates/active')->assertStatus(200);
        $this->withToken($token)->getJson('/api/v1/auto-dialer-campaigns')->assertStatus(200);
        $this->withToken($token)->getJson('/api/v1/auto-dialer-campaigns/lists')->assertStatus(200);
        // 404 (no settings configured) proves the scope gate passed.
        $this->assertNotSame(403, $this->withToken($token)->getJson('/api/v1/call-notifications/settings')->status());
    }

    public function test_sanctum_only_groups_still_deny_ungranted_keys(): void
    {
        $token = $this->keyFor([['resource' => 'extensions', 'level' => 'read']]);

        $this->withToken($token)->getJson('/api/v1/auto-dialer-campaigns')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/session-updates/active')->assertStatus(403);
    }

    public function test_cdr_statistics_works_for_api_key_principals(): void
    {
        // Regression: statistics() passed the principal to a User-typed helper;
        // an ApiKey principal caused a TypeError (HTTP 500).
        $token = $this->keyFor([['resource' => 'call-detail-records', 'level' => 'read']]);

        $this->withToken($token)->getJson('/api/v1/call-detail-records/statistics')->assertStatus(200);
    }

    public function test_dashboard_is_grantable_read_only(): void
    {
        $token = $this->keyFor([['resource' => 'dashboard', 'level' => 'read']]);

        $this->withToken($token)->getJson('/api/v1/dashboard/supervisor')->assertStatus(200);
    }

    public function test_trunks_are_grantable(): void
    {
        $org = Organization::factory()->create();
        CloudonixSettings::factory()->create([
            'organization_id' => $org->id,
            'domain_uuid' => 'dom-uuid-trunks',
        ]);
        [, $token] = app(ApiKeyService::class)->create(
            organizationId: $org->id, name: 'k',
            permissions: [['resource' => 'trunks', 'level' => 'read']],
            createdBy: null,
        );

        // Trunks are proxied to Cloudonix — fake the upstream list call.
        $trunksUrl = rtrim((string) config('cloudonix.api.base_url'), '/')
            .'/customers/self/domains/dom-uuid-trunks/trunks';
        Http::fake([$trunksUrl => Http::response([], 200)]);

        $this->withToken($token)->getJson('/api/v1/trunks')->assertStatus(200);

        // A key without the trunks grant is denied.
        $ungranted = $this->keyFor([['resource' => 'extensions', 'level' => 'read']]);
        $this->withToken($ungranted)->getJson('/api/v1/trunks')->assertStatus(403);
    }

    public function test_call_queues_are_grantable_read_only(): void
    {
        [, $token] = app(ApiKeyService::class)->create(
            organizationId: Organization::factory()->create()->id, name: 'k',
            permissions: [['resource' => 'call-queues', 'level' => 'read'], ['resource' => 'queue-calls', 'level' => 'read']],
            createdBy: null,
        );

        $org = Organization::factory()->create();
        $queue = \App\Models\CallQueue::factory()->create(['organization_id' => $org->id]);

        $this->withToken($token)->getJson('/api/v1/call-queues')->assertStatus(200);
        $this->withToken($token)->getJson('/api/v1/queue-calls')->assertStatus(200);

        // Read-level keys cannot mutate.
        $this->withToken($token)->postJson('/api/v1/call-queues', [])->assertStatus(403);

        // A key without the grants is denied.
        $ungranted = $this->keyFor([['resource' => 'extensions', 'level' => 'read']]);
        $this->withToken($ungranted)->getJson('/api/v1/call-queues')->assertStatus(403);
        $this->withToken($ungranted)->getJson('/api/v1/queue-calls')->assertStatus(403);
    }

    public function test_credential_subroutes_are_never_grantable(): void
    {
        $org = Organization::factory()->create();
        [, $token] = app(ApiKeyService::class)->create(
            organizationId: $org->id, name: 'k',
            permissions: [
                ['resource' => 'extensions', 'level' => 'write'],
                ['resource' => 'users', 'level' => 'write'],
            ],
            createdBy: null,
        );

        // Targets must exist: route-model binding (404) runs before the scope
        // gate, so a missing id would mask the authorization check.
        $extension = Extension::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        // SIP password endpoints stay user-token-only even with an extensions grant.
        $this->withToken($token)->getJson("/api/v1/extensions/{$extension->id}/password")->assertStatus(403);
        $this->withToken($token)->putJson("/api/v1/extensions/{$extension->id}/reset-password")->assertStatus(403);

        // Embed token management stays user-token-only even with a users grant.
        $this->withToken($token)->getJson("/api/v1/users/{$user->id}/embed-token")->assertStatus(403);
        $this->withToken($token)->patchJson("/api/v1/users/{$user->id}/password", [])->assertStatus(403);
    }

    /**
     * Drift guard: every named route in a key-enforced middleware group must
     * resolve to a grantable resource or be explicitly allowlisted as
     * user-token-only. Fails when a new route is added without a decision.
     */
    public function test_every_key_enforced_route_has_a_scope_decision(): void
    {
        /** Routes intentionally not grantable to API keys (identity, credentials, platform plumbing). */
        $nonGrantablePrefixes = [
            'api-keys.',
            'profile.',
            'settings.cloudonix.',
            'webphone.',
        ];

        $uncovered = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            if (! in_array(EnforceApiKeyScope::class, $middleware, true)) {
                continue;
            }
            if (GrantableResource::fromRouteName($name) !== null) {
                continue;
            }
            foreach ($nonGrantablePrefixes as $prefix) {
                if ($name === rtrim($prefix, '.') || str_starts_with($name, $prefix)) {
                    continue 2;
                }
            }
            $uncovered[] = $name;
        }

        $this->assertSame([], $uncovered, 'Key-enforced routes without a scope decision: '.implode(', ', $uncovered));
    }
}
