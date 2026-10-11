<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

final class ProductionSecretsGuardTest extends TestCase
{
    private function good(): void
    {
        config([
            'services.dialer_worker.token' => 'a1',
            'services.amd_worker.api_token' => 'b2',
            'services.acd_worker.api_token' => 'c3',
            'broadcasting.connections.pusher.secret' => 'd4',
        ]);
    }

    public function test_real_secrets_pass(): void
    {
        $this->good();
        (new AppServiceProvider($this->app))->assertNoDefaultSecrets();
        $this->addToAssertionCount(1);
    }

    public function test_empty_or_default_secrets_throw(): void
    {
        foreach ([
            ['services.dialer_worker.token', ''],
            ['services.amd_worker.api_token', null],
            ['services.acd_worker.api_token', 'dev-acd-token-change-in-production'],
            ['broadcasting.connections.pusher.secret', 'pbxappsecret'],
        ] as [$key, $bad]) {
            $this->good();
            config([$key => $bad]);

            try {
                (new AppServiceProvider($this->app))->assertNoDefaultSecrets();
                $this->fail("{$key} = ".var_export($bad, true).' should throw');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
