<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google reCAPTCHA v3 Validation Rule
 *
 * Validates the reCAPTCHA token by verifying it with Google's API.
 * This uses reCAPTCHA v3 which is invisible to users.
 */
class Recaptcha implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  string  $attribute  The name of the attribute being validated
     * @param  mixed  $value  The value of the attribute (the reCAPTCHA token)
     * @param  Closure  $fail  The callback to call if validation fails
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Skip validation if reCAPTCHA is disabled
        if (! config('services.recaptcha.enabled', false)) {
            return;
        }

        // Check if token is present
        if (empty($value) || ! is_string($value)) {
            $fail('reCAPTCHA verification is required.');

            return;
        }

        $secretKey = config('services.recaptcha.secret_key');

        if (empty($secretKey)) {
            Log::error('reCAPTCHA secret key is not configured');
            $fail('reCAPTCHA verification failed. Please try again.');

            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => $secretKey,
                    'response' => $value,
                    'remoteip' => request()->ip(),
                ]);

            $data = $response->json();

            if (! $data['success'] ?? false) {
                Log::warning('reCAPTCHA verification failed', [
                    'error_codes' => $data['error-codes'] ?? [],
                ]);
                $fail('reCAPTCHA verification failed. Please try again.');

                return;
            }

            // Check the score (0.0 to 1.0)
            $score = $data['score'] ?? 0;
            $minScore = (float) config('services.recaptcha.min_score', 0.5);

            if ($score < $minScore) {
                Log::warning('reCAPTCHA score too low', [
                    'score' => $score,
                    'min_score' => $minScore,
                ]);
                $fail('Your request could not be verified as legitimate. Please try again.');

                return;
            }

            // Validation passed
            Log::debug('reCAPTCHA verification passed', [
                'score' => $score,
                'action' => $data['action'] ?? 'unknown',
            ]);

        } catch (\Exception $e) {
            Log::error('reCAPTCHA verification error', [
                'error' => $e->getMessage(),
            ]);
            $fail('reCAPTCHA verification failed. Please try again.');
        }
    }
}
