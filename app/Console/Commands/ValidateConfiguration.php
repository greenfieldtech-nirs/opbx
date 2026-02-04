<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ApplicationConfig;
use Illuminate\Console\Command;

/**
 * Validate Application Configuration
 *
 * Validates that the application is correctly configured,
 * especially for production/SaaS deployment mode.
 */
class ValidateConfiguration extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'config:validate
                          {--silent : Only show errors}';

    /**
     * The console command description.
     */
    protected $description = 'Validate application configuration for deployment';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $quiet = $this->option('silent');

        if (! $quiet) {
            $this->info('Validating OpBX configuration...');
            $this->newLine();
        }

        // Check application mode
        $mode = ApplicationConfig::getMode();
        if (! $quiet) {
            $this->line("Application Mode: <fg=cyan>{$mode}</>");
        }

        // Check webhook URL configuration
        $hasWebhookUrl = ApplicationConfig::hasApplicationWebhookBaseUrl();
        if ($hasWebhookUrl) {
            $webhookUrl = ApplicationConfig::getApplicationWebhookBaseUrl();
            if (! $quiet) {
                $this->line("Application Webhook URL: <fg=green>{$webhookUrl}</>");
            }
        } elseif (ApplicationConfig::isProduction()) {
            if (! $quiet) {
                $this->line('Application Webhook URL: <fg=red>NOT SET</>');
            }
        } else {
            if (! $quiet) {
                $this->line('Application Webhook URL: <fg=gray>Not configured (per-organization)</>');
            }
        }

        if (! $quiet) {
            $this->newLine();
        }

        // Get warnings
        $warnings = ApplicationConfig::getConfigurationWarnings();

        if (empty($warnings)) {
            $this->info('✓ Configuration is valid');

            return self::SUCCESS;
        }

        // Display warnings
        $this->error('✗ Configuration issues detected:');
        $this->newLine();

        foreach ($warnings as $warning) {
            $this->line("  • {$warning}");
        }

        $this->newLine();

        // Provide guidance
        if (ApplicationConfig::isProduction() && ! $hasWebhookUrl) {
            $this->comment('To fix this issue:');
            $this->line('  1. Set OPBX_APPLICATION_WEBHOOK_BASEURL in your .env file');
            $this->line('  2. Ensure the URL is publicly accessible by Cloudonix');
            $this->line('  3. Use https:// for production deployments');
            $this->newLine();
            $this->line('Example:');
            $this->line('  OPBX_APPLICATION_WEBHOOK_BASEURL=https://saas.opbx.com');
        }

        return self::FAILURE;
    }
}
