<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Scopes\OrganizationScope;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\VoiceRouting\RingGroupConferenceService;
use App\Services\VoiceRouting\RingGroupRoutingService;
use App\Services\VoiceRouting\Strategies\RingGroupRoutingStrategy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Simultaneous ring group endpoints (HMAC-signed URLs, no Bearer auth: originated and application-switched
 * fetches may not carry it).
 *
 * - memberConnect: fetched by a member's own session once the member answers. The first member claims the
 *   call and joins the caller's room; every other member hangs up.
 * - fallback: fetched by the caller's session after nobody answered; runs the ring group's fallback.
 */
class RingGroupConferenceController extends Controller
{
    public function __construct(
        private readonly RingGroupConferenceService $conference,
    ) {}

    public function memberConnect(Request $request): Response
    {
        $context = $this->context($request);
        $legToken = (string) ($request->input('Session') ?: $request->input('CallSid'));

        if ($context === null || ! isset($context['extension']) || $legToken === '' || ! $this->conference->claim($context, $legToken)) {
            Log::info('RingGroupConferenceController: member session did not win, hanging up', [
                'ring_group_id' => $context['ring_group_id'] ?? null,
                'extension' => $context['extension'] ?? null,
            ]);

            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
        }

        return (new CxmlBuilder)
            ->dialQueueConference($this->conference->roomName((int) $context['ring_group_id'], (string) $context['caller_token']))
            ->toResponse();
    }

    public function fallback(Request $request): Response
    {
        $context = $this->context($request);
        $organizationId = (int) ($context['organization_id'] ?? 0);
        $ringGroup = $context === null ? null
            : OrganizationScope::bypass(fn () => app(RingGroupRoutingService::class)->findRingGroup((int) $context['ring_group_id'], $organizationId));

        if ($ringGroup === null) {
            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
        }

        $request->merge(['_organization_id' => $organizationId]);

        return OrganizationScope::bypass(fn () => app(RingGroupRoutingStrategy::class)->handleFallback($ringGroup, $request));
    }

    /** @return array<string, mixed>|null */
    private function context(Request $request): ?array
    {
        $context = $this->conference->verifiedContext((string) $request->query('sd', ''), (string) $request->query('sig', ''));

        if ($context === null) {
            Log::warning('RingGroupConferenceController: invalid or expired signature');
        }

        return $context;
    }
}
