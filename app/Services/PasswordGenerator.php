<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Password generator service that creates memorable passphrases
 * using three random English dictionary words.
 *
 * Generated passwords follow the format: Word1{digit}Word2{digit}Word3{special}
 * Example: Green4Mountain7Tiger!
 *
 * This provides strong entropy while being more memorable than random character strings.
 */
class PasswordGenerator
{
    private const MIN_LENGTH = 16;
    private const MAX_LENGTH = 32;

    /**
     * Curated list of common English words for passphrase generation.
     * Words are 4-8 characters long and easy to remember.
     *
     * @var array<string>
     */
    private const WORDS = [
        'amber', 'apple', 'azure', 'beach', 'berry', 'blade', 'blaze', 'bloom',
        'brave', 'breeze', 'bright', 'brook', 'cloud', 'coral', 'crane', 'creek',
        'crown', 'crystal', 'dance', 'dawn', 'delta', 'diamond', 'dove', 'dragon',
        'dream', 'eagle', 'earth', 'echo', 'ember', 'falcon', 'flame', 'flash',
        'forest', 'frost', 'galaxy', 'garden', 'gentle', 'glacier', 'golden', 'grace',
        'granite', 'grove', 'harbor', 'harmony', 'haven', 'hawk', 'hazel', 'heaven',
        'horizon', 'island', 'ivory', 'jade', 'jasper', 'journey', 'jungle', 'knight',
        'lake', 'laurel', 'legend', 'light', 'lunar', 'maple', 'marble', 'meadow',
        'melody', 'meteor', 'midnight', 'misty', 'monarch', 'moon', 'morning', 'moss',
        'mountain', 'mystic', 'nectar', 'noble', 'north', 'nova', 'ocean', 'olive',
        'onyx', 'opal', 'orchid', 'pearl', 'phoenix', 'pine', 'planet', 'platinum',
        'prairie', 'prism', 'quartz', 'quest', 'rain', 'rainbow', 'raven', 'ridge',
        'river', 'robin', 'rose', 'ruby', 'sage', 'sand', 'sapphire', 'scarlet',
        'shadow', 'silver', 'sky', 'snow', 'solar', 'spark', 'spirit', 'spring',
        'star', 'stone', 'storm', 'stream', 'summer', 'summit', 'sunrise', 'sunset',
        'swift', 'tango', 'thunder', 'tide', 'tiger', 'timber', 'topaz', 'tower',
        'tree', 'twilight', 'valley', 'velvet', 'violet', 'vision', 'wave', 'whisper',
        'willow', 'wind', 'winter', 'wisdom', 'wolf', 'wonder', 'zenith',
    ];

    private const SPECIAL_CHARS = '!@#$%&*-+=?';
    private const DIGITS = '0123456789';

    /**
     * Generate a memorable passphrase using three random dictionary words.
     *
     * Format: Word1{digit}Word2{digit}Word3{special}
     * Example: Green4Mountain7Tiger!
     *
     * @param int $wordCount Number of words to use (default: 3)
     * @return string Generated passphrase
     * @throws InvalidArgumentException If word count is invalid
     */
    public function generate(int $wordCount = 3): string
    {
        if ($wordCount < 2 || $wordCount > 5) {
            throw new InvalidArgumentException('Word count must be between 2 and 5');
        }

        $words = [];
        $usedIndices = [];

        // Select random unique words
        for ($i = 0; $i < $wordCount; $i++) {
            do {
                $index = random_int(0, count(self::WORDS) - 1);
            } while (in_array($index, $usedIndices, true));

            $usedIndices[] = $index;
            // Capitalize first letter for variety
            $words[] = ucfirst(self::WORDS[$index]);
        }

        // Build passphrase with separators
        $passphrase = '';
        for ($i = 0; $i < count($words); $i++) {
            $passphrase .= $words[$i];

            // Add digit separator between words (except after last word)
            if ($i < count($words) - 1) {
                $passphrase .= self::DIGITS[random_int(0, strlen(self::DIGITS) - 1)];
            }
        }

        // Add special character and number at the end to ensure requirements
        $passphrase .= self::SPECIAL_CHARS[random_int(0, strlen(self::SPECIAL_CHARS) - 1)];
        $passphrase .= self::DIGITS[random_int(0, strlen(self::DIGITS) - 1)];

        // Validate length
        if (strlen($passphrase) < self::MIN_LENGTH) {
            // If too short, add another word
            return $this->generate($wordCount + 1);
        }

        if (strlen($passphrase) > self::MAX_LENGTH) {
            // If too long, use fewer words
            return $this->generate($wordCount - 1);
        }

        return $passphrase;
    }

    /**
     * Validate if a password meets basic security requirements.
     *
     * Checks for:
     * - Length between 16-32 characters
     * - At least one uppercase letter
     * - At least one lowercase letter
     * - At least one digit
     * - At least one special character
     *
     * @param string $password Password to validate
     * @return bool True if password meets requirements
     */
    public function validate(string $password): bool
    {
        $length = strlen($password);

        // Check length
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return false;
        }

        // Check for required character types
        $hasUppercase = preg_match('/[A-Z]/', $password) === 1;
        $hasLowercase = preg_match('/[a-z]/', $password) === 1;
        $hasDigit = preg_match('/[0-9]/', $password) === 1;
        $hasSpecial = preg_match('/[' . preg_quote(self::SPECIAL_CHARS, '/') . ']/', $password) === 1;

        return $hasUppercase && $hasLowercase && $hasDigit && $hasSpecial;
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
            'format' => 'Three random English words with numbers and special character',
            'example' => 'Green4Mountain7Tiger!2',
            'has_uppercase' => true,
            'has_lowercase' => true,
            'has_digit' => true,
            'has_special' => true,
        ];
    }
}
