<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

class PasswordGenerator
{
    private const MIN_LENGTH = 16;
    private const MAX_LENGTH = 32;
    private const MIN_SPECIAL = 2;
    private const MIN_DIGITS = 2;
    private const MIN_LOWERCASE = 2;
    private const MIN_UPPERCASE = 2;

    private const SPECIAL_CHARS = '!@#$%^&*()-_=+[]{}|;:,.<>?';
    private const DIGITS = '0123456789';
    private const LOWERCASE = 'abcdefghijklmnopqrstuvwxyz';
    private const UPPERCASE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Generate a strong random password that meets all requirements.
     *
     * @param int $length Password length (16-32 characters)
     * @return string Generated password
     * @throws InvalidArgumentException If length is out of range
     */
    public function generate(int $length = 24): string
    {
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Password length must be between %d and %d characters', self::MIN_LENGTH, self::MAX_LENGTH)
            );
        }

        // Calculate remaining length after required characters
        $requiredCount = self::MIN_SPECIAL + self::MIN_DIGITS + self::MIN_LOWERCASE + self::MIN_UPPERCASE;
        if ($length < $requiredCount) {
            throw new InvalidArgumentException(
                sprintf('Password length must be at least %d to meet all requirements', $requiredCount)
            );
        }

        $password = [];

        // Add required special characters
        for ($i = 0; $i < self::MIN_SPECIAL; $i++) {
            $password[] = self::SPECIAL_CHARS[random_int(0, strlen(self::SPECIAL_CHARS) - 1)];
        }

        // Add required digits
        for ($i = 0; $i < self::MIN_DIGITS; $i++) {
            $password[] = self::DIGITS[random_int(0, strlen(self::DIGITS) - 1)];
        }

        // Add required lowercase letters
        for ($i = 0; $i < self::MIN_LOWERCASE; $i++) {
            $password[] = self::LOWERCASE[random_int(0, strlen(self::LOWERCASE) - 1)];
        }

        // Add required uppercase letters
        for ($i = 0; $i < self::MIN_UPPERCASE; $i++) {
            $password[] = self::UPPERCASE[random_int(0, strlen(self::UPPERCASE) - 1)];
        }

        // Fill remaining length with random characters from all sets
        $allChars = self::SPECIAL_CHARS . self::DIGITS . self::LOWERCASE . self::UPPERCASE;
        $remaining = $length - count($password);

        for ($i = 0; $i < $remaining; $i++) {
            $password[] = $allChars[random_int(0, strlen($allChars) - 1)];
        }

        // Shuffle the password to avoid predictable patterns
        shuffle($password);

        return implode('', $password);
    }

    /**
     * Validate if a password meets all requirements.
     *
     * @param string $password Password to validate
     * @return bool True if password meets all requirements
     */
    public function validate(string $password): bool
    {
        $length = strlen($password);

        // Check length
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return false;
        }

        // Count character types
        $specialCount = 0;
        $digitCount = 0;
        $lowercaseCount = 0;
        $uppercaseCount = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $password[$i];

            if (str_contains(self::SPECIAL_CHARS, $char)) {
                $specialCount++;
            } elseif (str_contains(self::DIGITS, $char)) {
                $digitCount++;
            } elseif (str_contains(self::LOWERCASE, $char)) {
                $lowercaseCount++;
            } elseif (str_contains(self::UPPERCASE, $char)) {
                $uppercaseCount++;
            }
        }

        return $specialCount >= self::MIN_SPECIAL
            && $digitCount >= self::MIN_DIGITS
            && $lowercaseCount >= self::MIN_LOWERCASE
            && $uppercaseCount >= self::MIN_UPPERCASE;
    }

    /**
     * Get password requirements as an array.
     *
     * @return array<string, mixed>
     */
    public function getRequirements(): array
    {
        return [
            'min_length' => self::MIN_LENGTH,
            'max_length' => self::MAX_LENGTH,
            'min_special' => self::MIN_SPECIAL,
            'min_digits' => self::MIN_DIGITS,
            'min_lowercase' => self::MIN_LOWERCASE,
            'min_uppercase' => self::MIN_UPPERCASE,
        ];
    }
}
