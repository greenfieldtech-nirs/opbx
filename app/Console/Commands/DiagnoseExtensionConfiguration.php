<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Extension;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Console\Command;

class DiagnoseExtensionConfiguration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'extensions:diagnose
                            {--organization-id= : Organization ID to check}
                            {--extension-number= : Specific extension number to check}
                            {--fix : Automatically fix issues where possible}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose and optionally fix extension configuration issues';

    public function __construct(
        private readonly VoiceRoutingManager $routingManager
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $organizationId = $this->option('organization-id');
        $extensionNumber = $this->option('extension-number');
        $shouldFix = $this->option('fix');

        $query = Extension::query();

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        if ($extensionNumber) {
            $query->where('extension_number', $extensionNumber);
        }

        $extensions = $query->get();

        if ($extensions->isEmpty()) {
            $this->info('No extensions found matching the criteria.');
            return;
        }

        $this->info("Checking {$extensions->count()} extension(s)...");
        $this->newLine();

        $issuesFound = 0;
        $fixesApplied = 0;

        foreach ($extensions as $extension) {
            $result = $this->routingManager->validateExtensionConfiguration($extension);

            if ($result['has_issues']) {
                $issuesFound++;
                $this->error("❌ Extension {$result['extension_number']} ({$result['type']})");
                $this->line('   Issues:');
                foreach ($result['issues'] as $issue) {
                    $this->line("   - {$issue}");
                }

                if (!empty($result['suggestions'])) {
                    $this->line('   Suggestions:');
                    foreach ($result['suggestions'] as $suggestion) {
                        $this->line("   → {$suggestion}");
                    }
                }

                // Try to auto-fix if requested
                if ($shouldFix && $this->attemptAutoFix($extension, $result)) {
                    $fixesApplied++;
                    $this->info("   ✅ Auto-fixed extension {$result['extension_number']}");
                }

                $this->newLine();
            } else {
                $this->info("✅ Extension {$result['extension_number']} ({$result['type']}) - OK");
            }
        }

        $this->newLine();
        $this->info("Summary: {$issuesFound} issue(s) found, {$fixesApplied} fix(es) applied");
    }

    private function attemptAutoFix(Extension $extension, array $result): bool
    {
        if ($extension->type->value === 'ring_group' && empty($extension->configuration['ring_group_id'])) {
            // Try to find a ring group with matching name
            $ringGroup = \App\Models\RingGroup::where('name', $extension->extension_number)
                ->where('organization_id', $extension->organization_id)
                ->first();

            if ($ringGroup) {
                $config = $extension->configuration ?? [];
                $config['ring_group_id'] = $ringGroup->id;
                $extension->configuration = $config;
                $extension->save();

                $this->line("   → Set ring_group_id to {$ringGroup->id} for ring group '{$ringGroup->name}'");
                return true;
            }
        }

        return false;
    }
}
