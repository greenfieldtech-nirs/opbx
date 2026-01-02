<?php

declare(strict_types=1);

namespace Tests\Mocks\VoiceRouting;

use App\Models\DidNumber;
use Illuminate\Http\Request;

class MockSentryService
{
    public function checkInbound(Request $request, DidNumber $did): bool
    {
        // Default to allow all in mocks unless configured otherwise
        return true;
    }
}
