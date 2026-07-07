<?php

declare(strict_types=1);

namespace App\Services\CallTracking;

use App\Models\CallDetailRecord;
use App\Models\CallTrackingNumber;
use App\Models\CallTrackingSession;
use App\Scopes\OrganizationScope;
use Illuminate\Support\Facades\Log;

/**
 * Create CallTrackingSession records from CDRs.
 */
class CallTrackingSessionService
{
    public function __construct(
        private readonly ConversionRuleEvaluator $conversionRuleEvaluator
    ) {}

    /**
     * Create a tracking session from a CDR if the called number belongs to a campaign.
     */
    public function createFromCDR(CallDetailRecord $cdr, int $organizationId): ?CallTrackingSession
    {
        Log::debug('CallTrackingSessionService: Looking up tracking number', [
            'call_id' => $cdr->call_id,
            'organization_id' => $organizationId,
            'called_number' => $cdr->to,
        ]);

        $trackingNumber = CallTrackingNumber::withoutGlobalScope(OrganizationScope::class)
            ->join('did_numbers', 'call_tracking_numbers.did_number_id', '=', 'did_numbers.id')
            ->where('did_numbers.organization_id', $organizationId)
            ->where('did_numbers.phone_number', $cdr->to)
            ->where('call_tracking_numbers.status', 'active')
            ->select('call_tracking_numbers.*')
            ->with(['campaign' => function ($query) {
                $query->withoutGlobalScope(OrganizationScope::class);
            }])
            ->first();

        if (! $trackingNumber) {
            Log::info('CallTrackingSessionService: No active tracking number for CDR', [
                'call_id' => $cdr->call_id,
                'organization_id' => $organizationId,
                'called_number' => $cdr->to,
            ]);

            return null;
        }

        if (! $trackingNumber->campaign) {
            Log::info('CallTrackingSessionService: Tracking number has no campaign', [
                'call_id' => $cdr->call_id,
                'tracking_number_id' => $trackingNumber->id,
                'campaign_id' => $trackingNumber->call_tracking_campaign_id,
            ]);

            return null;
        }

        if (! $trackingNumber) {
            Log::info('CallTrackingSessionService: No active tracking number for CDR', [
                'call_id' => $cdr->call_id,
                'organization_id' => $organizationId,
                'called_number' => $cdr->to,
            ]);

            return null;
        }

        if (! $trackingNumber->campaign) {
            Log::info('CallTrackingSessionService: Tracking number has no campaign', [
                'call_id' => $cdr->call_id,
                'tracking_number_id' => $trackingNumber->id,
                'campaign_id' => $trackingNumber->call_tracking_campaign_id,
            ]);

            return null;
        }

        $campaign = $trackingNumber->campaign;

        if (! $campaign->isActive()) {
            Log::info('CallTrackingSessionService: Campaign is inactive', [
                'call_id' => $cdr->call_id,
                'campaign_id' => $campaign->id,
            ]);

            return null;
        }

        $isConverted = $this->conversionRuleEvaluator->evaluate(
            $campaign->conversion_rule ?? [],
            $cdr->disposition,
            $cdr->billsec
        );

        $session = CallTrackingSession::create([
            'organization_id' => $organizationId,
            'call_tracking_campaign_id' => $campaign->id,
            'call_tracking_number_id' => $trackingNumber->id,
            'did_number_id' => $trackingNumber->did_number_id,
            'call_id' => $cdr->call_id,
            'session_id' => $cdr->session_id ? (string) $cdr->session_id : null,
            'caller_number' => $cdr->from,
            'called_number' => $cdr->to,
            'source' => $campaign->source,
            'medium' => $campaign->medium,
            'campaign_name' => $campaign->name,
            'disposition' => $cdr->disposition,
            'duration' => $cdr->duration,
            'billsec' => $cdr->billsec,
            'is_answered' => $cdr->billsec > 0,
            'is_converted' => $isConverted,
            'conversion_value' => $isConverted ? ($campaign->conversion_rule['conversion_value'] ?? null) : null,
            'started_at' => $cdr->call_start_time ?? $cdr->session_timestamp ?? now(),
            'answered_at' => $cdr->call_answer_time,
            'ended_at' => $cdr->call_end_time,
            'raw_cdr' => $cdr->raw_cdr,
        ]);

        Log::info('CallTrackingSessionService: Created tracking session from CDR', [
            'call_id' => $cdr->call_id,
            'session_id' => $session->id,
            'campaign_id' => $campaign->id,
            'is_converted' => $isConverted,
        ]);

        return $session;
    }
}
