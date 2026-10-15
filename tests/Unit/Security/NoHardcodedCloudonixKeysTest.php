<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A real Cloudonix API key was once hardcoded in a console command and five
 * tests. Keys look like "XI" + 32 hex chars; fixtures must use obvious fakes.
 */
class NoHardcodedCloudonixKeysTest extends TestCase
{
    public function test_no_cloudonix_api_keys_in_source(): void
    {
        $root = dirname(__DIR__, 3);
        $hits = [];

        foreach (['app', 'config', 'database', 'routes', 'tests'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php' && preg_match('/\bXI[0-9A-Fa-f]{32}\b/', (string) file_get_contents($file->getPathname()))) {
                    $hits[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $hits, 'Hardcoded Cloudonix API key found; use env or a fake like XI-test-not-a-real-key.');
    }
}
