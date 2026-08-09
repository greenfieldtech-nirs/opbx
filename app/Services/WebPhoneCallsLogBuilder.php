<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExtensionType;
use App\Models\CallDetailRecord;
use App\Models\Extension;
use App\Models\User;
use App\Scopes\OrganizationScope;

final class WebPhoneCallsLogBuilder
{
    /**
     * Build the recent-calls list for a user's USER-type extension.
     * Excludes coaching sentinel destinations. Returns [] when the user
     * has no extension.
     *
     * Scope is bypassed so this works in both authenticated (SPA) and
     * tokenless (embed middleware) contexts; queries are constrained to the
     * user's own organization_id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildForUser(User $user): array
    {
        return OrganizationScope::bypass(function () use ($user) {
            $extension = Extension::where('user_id', $user->id)
                ->where('type', ExtensionType::USER)
                ->where('organization_id', $user->organization_id)
                ->first();

            if (! $extension) {
                return [];
            }

            $records = CallDetailRecord::forOrganization($user->organization_id)
                ->where('from', $extension->extension_number)
                ->where('to', 'not like', 'spy\_%')
                ->where('to', 'not like', 'barge\_%')
                ->where('to', 'not like', 'whisper\_%')
                ->orderByDesc('session_timestamp')
                ->limit(50)
                ->get();

            return $records->map(fn (CallDetailRecord $cdr) => [
                'to' => $cdr->to,
                'session_timestamp' => $cdr->session_timestamp?->toIso8601String(),
                'duration' => (int) $cdr->duration,
                'duration_formatted' => $cdr->formatted_duration,
                'disposition' => $cdr->disposition,
            ])->all();
        });
    }
}
