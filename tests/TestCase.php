<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Safety guard: must run BEFORE parent::setUp(), because RefreshDatabase
        // executes migrate:fresh inside it. A cached config (bootstrap/cache/config.php
        // is host-mounted into containers) or a leaked real env var would otherwise
        // wipe the production database. See phpunit.xml force="true" note.
        if (file_exists(__DIR__.'/../bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'bootstrap/cache/config.php exists: cached config ignores test env overrides. Run "php artisan config:clear" before testing.'
            );
        }
        foreach ([$_SERVER['DB_DATABASE'] ?? null, $_ENV['DB_DATABASE'] ?? null, getenv('DB_DATABASE') ?: null] as $db) {
            if ($db !== null && $db !== '' && $db !== 'opbx_test') {
                throw new \RuntimeException("Refusing to run tests against non-test database [{$db}].");
            }
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // Ensure Mockery is closed before parent tearDown to prevent
        // errors in tearDown from skipping HandleExceptions::flushState().
        // This fixes PHPUnit 11 "did not remove its own error handlers" warnings.
        if (class_exists(\Mockery::class)) {
            try {
                \Mockery::close();
            } catch (\Throwable $e) {
                // Ignore Mockery exceptions – we must still run parent::tearDown()
                // so that HandleExceptions::flushState() cleans up error handlers.
            }
        }

        parent::tearDown();
    }
}
