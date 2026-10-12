<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

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
        foreach ([
            $_SERVER['DB_DATABASE'] ?? null, $_ENV['DB_DATABASE'] ?? null, getenv('DB_DATABASE') ?: null,
            $_SERVER['DB_CDR_ARCHIVE_DATABASE'] ?? null, $_ENV['DB_CDR_ARCHIVE_DATABASE'] ?? null, getenv('DB_CDR_ARCHIVE_DATABASE') ?: null,
        ] as $db) {
            if ($db !== null && $db !== '' && $db !== 'opbx_test') {
                throw new \RuntimeException("Refusing to run tests against non-test database [{$db}].");
            }
        }

        parent::setUp();

        // No test may reach a real host: unfaked HTTP calls fail loudly instead of
        // hitting live Cloudonix/customer endpoints (and stay deterministic).
        Http::preventStrayRequests();

        // Redis is shared with the live stack (db0 holds acd:*/dialer:* keys the
        // workers read), so tests must stay on the dedicated test DBs before any
        // test code can write or flush.
        $redis = config('database.redis');
        if ((string) $redis['default']['database'] !== '15'
            || (string) $redis['cache']['database'] !== '14'
            || (string) $redis['dialer']['database'] !== '15'
            || ! str_starts_with((string) $redis['options']['prefix'], 'opbx-test-')) {
            throw new \RuntimeException('Refusing to run tests against non-test Redis databases; see phpunit.xml.dist REDIS_* overrides.');
        }
    }

    /**
     * Runs after the app boots (config resolved incl. .env) and before RefreshDatabase migrates:
     * the cdr_archive connection must also point at opbx_test, never the live archive schema.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        foreach (['mysql', 'cdr_archive'] as $connection) {
            $db = config("database.connections.{$connection}.database");
            if ($db !== 'opbx_test') {
                throw new \RuntimeException("Refusing to run tests: connection [{$connection}] points at [{$db}], not opbx_test.");
            }
        }
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
