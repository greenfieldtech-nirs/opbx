<?php

declare(strict_types=1);

namespace App\Enums;

enum AiCallAttemptStatus: string
{
    case IN_PROGRESS = 'in_progress';
    case ANSWERED = 'answered';
    case FAILED = 'failed';
    /** Auto-dialer call that voicemail detection did not confirm as a person: not an AI call. */
    case EXCLUDED = 'excluded';
}
