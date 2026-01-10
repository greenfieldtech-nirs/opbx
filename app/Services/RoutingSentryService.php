<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DidNumber;
use Illuminate\Http\Request;

/**
 * Service for checking inbound call routing permissions and validations.
 */
class RoutingSentryService
{
    /**
     * Check if an inbound call is allowed to proceed.
     *
     * @param Request $request The incoming webhook request
     * @param DidNumber $did The DID number being called
     * @return array{allowed: bool, reason?: string} Check result with optional reason
     */
    public function checkInbound(Request $request, DidNumber $did): array
    {
        // Default implementation allows all calls
        // This can be extended with business logic for blocking calls
        return ['allowed' => true];
    }
}