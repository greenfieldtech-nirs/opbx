<?php

declare(strict_types=1);

namespace App\Services\Security\Checks;

use App\Models\DidNumber;
use Illuminate\Http\Request;

interface SentryCheck
{
    /**
     * Check if the call passes the security check.
     *
     * @param Request $request The incoming webhook request
     * @param DidNumber $did The DID number being called
     * @return bool True if passed, false if blocked
     */
    public function check(Request $request, DidNumber $did): bool;

    /**
     * Get the failure reason if the check failed.
     */
    public function getFailureReason(): string;

    /**
     * Get the suggested action if blocked (e.g., 'reject', 'redirect').
     */
    public function getAction(): string;
}
