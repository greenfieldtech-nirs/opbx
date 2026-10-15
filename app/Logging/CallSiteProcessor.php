<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Level;
use Monolog\Processor\IntrospectionProcessor;

/**
 * Adds extra.file / extra.line pointing at the APPLICATION call site of every
 * log record (skipping Laravel + Monolog frames). OpbxJsonFormatter renders
 * the file basename as the [File.php] prefix of each msg.
 *
 * Registered as a plain class name in config/logging.php so config:cache
 * keeps working (no closures in config).
 */
class CallSiteProcessor extends IntrospectionProcessor
{
    public function __construct()
    {
        parent::__construct(Level::Debug, ['Illuminate\\']);
    }
}
