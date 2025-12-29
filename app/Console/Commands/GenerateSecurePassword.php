<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generate cryptographically secure random passwords for .env configuration.
 *
 * This command helps administrators generate strong passwords and secrets
 * for database credentials, Redis passwords, webhook secrets, etc.
 */
class GenerateSecurePassword extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:password
                            {--length=32 : Length of the password in bytes (default: 32)}
                            {--format=base64 : Output format: base64, hex, or raw (default: base64)}
                            {--count=1 : Number of passwords to generate (default: 1)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate cryptographically secure random passwords for .env configuration';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $length = (int) $this->option('length');
        $format = $this->option('format');
        $count = (int) $this->option('count');

        // Validate length
        if ($length < 16) {
            $this->error('Password length must be at least 16 bytes for security.');
            $this->info('Recommended lengths:');
            $this->info('  - 32 bytes: Database passwords, Redis passwords');
            $this->info('  - 64 bytes: Webhook secrets, API tokens');

            return 1;
        }

        if ($length > 256) {
            $this->error('Password length cannot exceed 256 bytes.');

            return 1;
        }

        // Validate format
        if (!in_array($format, ['base64', 'hex', 'raw'], true)) {
            $this->error('Invalid format. Use: base64, hex, or raw');

            return 1;
        }

        // Validate count
        if ($count < 1 || $count > 10) {
            $this->error('Count must be between 1 and 10.');

            return 1;
        }

        $this->info('');
        $this->info('Generating secure random password(s)...');
        $this->info('');

        for ($i = 0; $i < $count; $i++) {
            $password = $this->generatePassword($length, $format);

            if ($count > 1) {
                $this->line("<fg=bright-green>Password " . ($i + 1) . ':</> ' . $password);
            } else {
                $this->line('<fg=bright-green>Password:</> ' . $password);
            }
        }

        $this->info('');
        $this->info('⚠️  Security Reminders:');
        $this->info('  1. Store passwords securely in your .env file');
        $this->info('  2. Never commit .env to version control');
        $this->info('  3. Use different passwords for each service');
        $this->info('  4. Rotate passwords periodically');
        $this->info('');

        return 0;
    }

    /**
     * Generate a cryptographically secure password.
     *
     * @param int $length Length in bytes
     * @param string $format Output format
     * @return string Generated password
     */
    private function generatePassword(int $length, string $format): string
    {
        $bytes = random_bytes($length);

        return match ($format) {
            'hex' => bin2hex($bytes),
            'base64' => base64_encode($bytes),
            'raw' => $bytes,
            default => base64_encode($bytes),
        };
    }
}
