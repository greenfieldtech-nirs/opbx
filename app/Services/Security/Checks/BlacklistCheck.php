<?php

declare(strict_types=1);

namespace App\Services\Security\Checks;

use App\Models\DidNumber;
use App\Models\SentryBlacklist;
use Illuminate\Http\Request;

class BlacklistCheck implements SentryCheck
{
    private string $failureReason = '';

    public function check(Request $request, DidNumber $did): bool
    {
        $from = $request->input('From');
        // $to = $request->input('To');

        // 1. Check DID-specific blacklists
        $didBlacklists = $did->sentryBlacklists()->get();
        if ($this->checkBlacklists($didBlacklists, $from)) {
            return false;
        }

        // 2. Check Organization-wide blacklists
        // We need to fetch organization blacklists that are NOT attached to specific DIDs?
        // Or usually org-wide blacklists apply to all DIDs.
        // Let's assume for now we check explicitly attached checking based on DID context.
        // If the requirement is global org blacklists, we should query them.

        // Implementation Plan Step 4.2 says "Blacklist verification".
        // Let's check organization blacklists too.

        // 2. Check Organization-wide blacklists
        // Currently we only enforce blacklists explicitly attached to the DID.
        // Future: Support global organization blacklists.

        return true;
    }

    private function checkBlacklists($blacklists, $number): bool
    {
        foreach ($blacklists as $blacklist) {
            // Manual list check
            if ($blacklist->type === 'manual') {
                // Check items
                $exists = $blacklist->items()->where('pattern', $number)->exists();
                if ($exists) {
                    $this->failureReason = "Number found in blacklist: {$blacklist->name}";
                    return true; // BLOCKED
                }
            }

            // TODO: Dynamic lists (e.g. spam database API)
        }
        return false;
    }

    public function getFailureReason(): string
    {
        return $this->failureReason;
    }

    public function getAction(): string
    {
        return 'reject';
    }
}
