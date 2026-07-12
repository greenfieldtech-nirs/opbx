<?php

declare(strict_types=1);

namespace App\Enums;

enum ApiKeyPermissionLevel: string
{
    case READ = 'read';
    case WRITE = 'write';

    /**
     * Does this level permit the given HTTP method?
     * READ => GET/HEAD only. WRITE => all (write implies read).
     */
    public function permitsMethod(string $method): bool
    {
        $method = strtoupper($method);

        if ($this === self::WRITE) {
            return true;
        }

        return in_array($method, ['GET', 'HEAD'], true);
    }
}
