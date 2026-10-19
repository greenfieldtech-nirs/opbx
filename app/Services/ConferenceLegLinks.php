<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Remembers which caller session an originated conference leg serves (queue
 * agents, simultaneous ring group members), so Live Calls shows the caller and
 * the leg as one call and acts on the caller.
 *
 * Redis: call:leg:{legToken} string caller session token
 */
class ConferenceLegLinks
{
    private const PREFIX = 'call:leg:';

    // shortcut: a link outlives any realistic call; a call longer than this shows its legs again.
    private const TTL_SECONDS = 4 * 60 * 60;

    /**
     * @param  iterable<string>  $legTokens
     */
    public function link(iterable $legTokens, string $callerToken): void
    {
        foreach ($legTokens as $legToken) {
            Redis::setex(self::PREFIX.$legToken, self::TTL_SECONDS, $callerToken);
        }
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<string, string> leg token => caller token, for the tokens that are linked legs
     */
    public function callersOf(array $tokens): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if ($tokens === []) {
            return [];
        }

        $callers = Redis::mget(array_map(fn ($token) => self::PREFIX.$token, $tokens));

        return array_filter(array_combine($tokens, $callers), fn ($caller) => is_string($caller) && $caller !== '');
    }
}
