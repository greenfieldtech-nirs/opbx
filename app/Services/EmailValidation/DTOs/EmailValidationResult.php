<?php

declare(strict_types=1);

namespace App\Services\EmailValidation\DTOs;

/**
 * Email Validation Result DTO
 *
 * Contains the result of an email validation check, including all
 * flags returned by the validation service and the final validity determination.
 */
class EmailValidationResult
{
    /**
     * Create a new email validation result.
     *
     * @param  bool  $isValid  Whether the email passed all validation checks
     * @param  string  $checkedEmail  The email address that was checked
     * @param  bool  $isDisposable  Whether the email uses a disposable domain
     * @param  bool  $isBlocklisted  Whether the email's domain is blocklisted
     * @param  bool  $isSpam  Whether the email's domain is associated with spam
     * @param  bool  $isRoleAccount  Whether the email is a role account (admin@, support@, etc.)
     * @param  bool  $isRelayDomain  Whether the email uses a relay/forwarding domain
     * @param  bool  $isPublicDomain  Whether the email uses a public provider (Gmail, Yahoo, etc.)
     * @param  ?string  $suggestedEmail  Suggested correction if a typo was detected
     * @param  ?string  $normalizedEmail  The normalized form of the email
     * @param  ?string  $errorMessage  Error message if validation failed
     * @param  ?string  $failedReason  Which specific check caused the failure (disposable, spam, etc.)
     */
    public function __construct(
        public bool $isValid,
        public string $checkedEmail,
        public bool $isDisposable = false,
        public bool $isBlocklisted = false,
        public bool $isSpam = false,
        public bool $isRoleAccount = false,
        public bool $isRelayDomain = false,
        public bool $isPublicDomain = false,
        public ?string $suggestedEmail = null,
        public ?string $normalizedEmail = null,
        public ?string $errorMessage = null,
        public ?string $failedReason = null,
    ) {}

    /**
     * Create a successful validation result.
     */
    public static function success(string $email, array $apiResponse): self
    {
        return new self(
            isValid: true,
            checkedEmail: $email,
            isDisposable: $apiResponse['disposable'] ?? false,
            isBlocklisted: $apiResponse['blocklisted'] ?? false,
            isSpam: $apiResponse['spam'] ?? false,
            isRoleAccount: $apiResponse['role_account'] ?? false,
            isRelayDomain: $apiResponse['relay_domain'] ?? false,
            isPublicDomain: $apiResponse['public_domain'] ?? false,
            suggestedEmail: $apiResponse['did_you_mean'] ?? null,
            normalizedEmail: $apiResponse['normalized_email'] ?? null,
        );
    }

    /**
     * Create a failed validation result.
     */
    public static function failure(
        string $email,
        string $reason,
        ?string $errorMessage = null,
        ?string $suggestedEmail = null,
    ): self {
        return new self(
            isValid: false,
            checkedEmail: $email,
            failedReason: $reason,
            errorMessage: $errorMessage ?? "Email validation failed: {$reason}",
            suggestedEmail: $suggestedEmail,
        );
    }

    /**
     * Create a result from the full API response with validation rules applied.
     *
     * @param  array  $config  Validation configuration from config/services.php
     */
    public static function fromApiResponse(string $email, array $apiResponse, array $config): self
    {
        $isDisposable = $apiResponse['disposable'] ?? false;
        $isBlocklisted = $apiResponse['blocklisted'] ?? false;
        $isSpam = $apiResponse['spam'] ?? false;
        $isRoleAccount = $apiResponse['role_account'] ?? false;
        $isRelayDomain = $apiResponse['relay_domain'] ?? false;
        $isPublicDomain = $apiResponse['public_domain'] ?? false;
        $suggestedEmail = $apiResponse['did_you_mean'] ?? null;
        $normalizedEmail = $apiResponse['normalized_email'] ?? null;

        // Check each validation rule based on configuration
        if ($config['block_disposable'] && $isDisposable) {
            return self::failure($email, 'disposable', 'Disposable email addresses are not allowed.', $suggestedEmail);
        }

        if ($config['block_blocklisted'] && $isBlocklisted) {
            return self::failure($email, 'blocklisted', 'This email domain is not allowed.', $suggestedEmail);
        }

        if ($config['block_spam'] && $isSpam) {
            return self::failure($email, 'spam', 'This email address cannot be used.', $suggestedEmail);
        }

        if ($config['block_role_accounts'] && $isRoleAccount) {
            return self::failure($email, 'role_account', 'Role-based email addresses (e.g., admin@, info@) are not allowed.', $suggestedEmail);
        }

        if ($config['block_relay_domains'] && $isRelayDomain) {
            return self::failure($email, 'relay_domain', 'Email forwarding addresses are not allowed.', $suggestedEmail);
        }

        if ($config['block_public_domains'] && $isPublicDomain) {
            return self::failure($email, 'public_domain', 'Public email providers are not allowed for this registration.', $suggestedEmail);
        }

        // All checks passed
        return new self(
            isValid: true,
            checkedEmail: $email,
            isDisposable: $isDisposable,
            isBlocklisted: $isBlocklisted,
            isSpam: $isSpam,
            isRoleAccount: $isRoleAccount,
            isRelayDomain: $isRelayDomain,
            isPublicDomain: $isPublicDomain,
            suggestedEmail: $suggestedEmail,
            normalizedEmail: $normalizedEmail,
        );
    }
}
