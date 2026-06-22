<?php

declare(strict_types=1);

namespace App\Services\CallTracking;

/**
 * Evaluate whether a call tracking session meets the campaign conversion rule.
 */
class ConversionRuleEvaluator
{
    /**
     * Determine whether a call should be marked as converted.
     *
     * @param  array<string, mixed>  $rule
     * @param  string  $disposition  The CDR disposition (e.g., CONNECTED)
     * @param  int  $billsec  Connected/billable duration in seconds
     */
    public function evaluate(array $rule, string $disposition, int $billsec): bool
    {
        $requiresAnswered = $rule['requires_answered_disposition'] ?? true;

        if ($requiresAnswered && ! $this->isAnsweredDisposition($disposition)) {
            return false;
        }

        $threshold = (int) ($rule['min_answered_duration_seconds'] ?? 0);

        return $billsec >= $threshold;
    }

    /**
     * Check if the disposition indicates the call was answered.
     */
    private function isAnsweredDisposition(string $disposition): bool
    {
        return in_array(strtoupper($disposition), ['CONNECTED', 'ANSWERED'], true);
    }
}
