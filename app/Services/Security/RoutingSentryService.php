<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\DidNumber;
use App\Services\Security\Checks\BlacklistCheck;
use App\Services\Security\Checks\SentryCheck;
use App\Services\Security\Checks\VelocityCheck;
use App\Services\Security\Checks\VolumeCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RoutingSentryService
{
    private array $checks = [];

    public function __construct()
    {
        // Register checks
        // We can use dependency injection here or manually instantiate
        $this->checks = [
            new BlacklistCheck(),
            new VelocityCheck(),
            new VolumeCheck(),
        ];
    }

    /**
     * Check inbound call against security rules.
     *
     * @return array{allowed: bool, reason: ?string, action: ?string}
     */
    public function checkInbound(Request $request, DidNumber $did): array
    {
        foreach ($this->checks as $check) {
            /** @var SentryCheck $check */
            if (!$check->check($request, $did)) {

                Log::warning('RoutingSentry: Call blocked', [
                    'check' => get_class($check),
                    'from' => $request->input('From'),
                    'to' => $request->input('To'),
                    'reason' => $check->getFailureReason(),
                ]);

                return [
                    'allowed' => false,
                    'reason' => $check->getFailureReason(),
                    'action' => $check->getAction(),
                ];
            }
        }

        return ['allowed' => true, 'reason' => null, 'action' => null];
    }

    /**
     * Check outbound call against security rules.
     *
     * @return array{allowed: bool, reason: ?string, action: ?string}
     */
    public function checkOutbound(Request $request, int $organizationId): array
    {
        // Placeholder for outbound security checks (e.g. international dialing restrictions)
        return ['allowed' => true, 'reason' => null, 'action' => null];
    }
}
