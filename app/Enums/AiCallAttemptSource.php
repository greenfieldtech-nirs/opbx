<?php

declare(strict_types=1);

namespace App\Enums;

enum AiCallAttemptSource: string
{
    /** Voice routing straight to an assistant (DID, extension, IVR, fallbacks). */
    case ROUTING = 'routing';
    case LOAD_BALANCER = 'load_balancer';
    /** The next load-balancer member after a failed one. */
    case FAILOVER = 'failover';
    case AUTO_DIALER = 'auto_dialer';
}
