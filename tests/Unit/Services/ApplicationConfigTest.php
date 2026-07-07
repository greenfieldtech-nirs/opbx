<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ApplicationConfig;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ApplicationConfigTest extends TestCase
{
    public function test_summary_includes_auth0_when_enabled(): void
    {
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', 'tenant.us.auth0.com');
        Config::set('services.auth0.client_id', 'client-id');
        Config::set('services.auth0.providers', ['google', 'github']);

        $summary = ApplicationConfig::getConfigurationSummary();

        $this->assertTrue($summary['saas_enabled']);
        $this->assertTrue($summary['auth0']['enabled']);
        $this->assertSame('tenant.us.auth0.com', $summary['auth0']['domain']);
        $this->assertSame('client-id', $summary['auth0']['client_id']);
        $this->assertSame(['google', 'github'], $summary['auth0']['providers']);
    }

    public function test_summary_hides_auth0_when_disabled(): void
    {
        Config::set('services.auth0.enabled', false);

        $summary = ApplicationConfig::getConfigurationSummary();

        $this->assertFalse($summary['saas_enabled']);
        $this->assertFalse($summary['auth0']['enabled']);
        $this->assertArrayNotHasKey('client_secret', $summary['auth0']);
    }
}
