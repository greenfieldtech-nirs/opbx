<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
