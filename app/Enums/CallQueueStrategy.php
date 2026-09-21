<?php

declare(strict_types=1);

namespace App\Enums;

enum CallQueueStrategy: string
{
    case RING_ALL = 'ring_all';
    case ROUND_ROBIN = 'round_robin';
    case LEAST_TALK_TIME = 'least_talk_time';
    case FEWEST_CALLS = 'fewest_calls';

    public function label(): string
    {
        return match ($this) {
            self::RING_ALL => 'Ring All',
            self::ROUND_ROBIN => 'Round Robin',
            self::LEAST_TALK_TIME => 'Least Talk Time',
            self::FEWEST_CALLS => 'Fewest Calls',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RING_ALL => 'Ring all available agents at the same time',
            self::ROUND_ROBIN => 'Distribute calls evenly across agents',
            self::LEAST_TALK_TIME => 'Offer the call to the agent with the least talk time',
            self::FEWEST_CALLS => 'Offer the call to the agent with the fewest handled calls',
        };
    }
}
