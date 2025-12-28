<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for CallDetailRecord model.
 *
 * Transforms CDR data into a standardized JSON response format.
 */
class CallDetailRecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,

            // Session information
            'session_timestamp' => $this->session_timestamp?->toIso8601String(),
            'session_token' => $this->session_token,
            'session_id' => $this->session_id,

            // Call participants
            'from' => $this->from,
            'to' => $this->to,

            // Call details
            'disposition' => $this->disposition,
            'duration' => $this->duration,
            'duration_formatted' => $this->formatted_duration,
            'billsec' => $this->billsec,
            'billsec_formatted' => $this->formatted_billsec,
            'call_id' => $this->call_id,

            // Session timing
            'call_start_time' => $this->call_start_time?->toIso8601String(),
            'call_end_time' => $this->call_end_time?->toIso8601String(),
            'call_answer_time' => $this->call_answer_time?->toIso8601String(),
            'status' => $this->status,

            // Routing information
            'domain' => $this->domain,
            'subscriber' => $this->subscriber,
            'cx_trunk_id' => $this->cx_trunk_id,
            'application' => $this->application,
            'route' => $this->route,
            'vapp_server' => $this->vapp_server,

            // Cost information
            'rated_cost' => $this->rated_cost ? (float) $this->rated_cost : null,
            'approx_cost' => $this->approx_cost ? (float) $this->approx_cost : null,
            'sell_cost' => $this->sell_cost ? (float) $this->sell_cost : null,

            // Complete raw CDR (only when explicitly requested via ?include=raw_cdr)
            'raw_cdr' => $this->when(
                $request->input('include') === 'raw_cdr',
                $this->raw_cdr
            ),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
