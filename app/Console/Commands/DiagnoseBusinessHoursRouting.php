<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BusinessHoursSchedule;
use App\Models\DidNumber;
use App\Scopes\OrganizationScope;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Diagnose business hours routing configuration and identify issues.
 */
class DiagnoseBusinessHoursRouting extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'diagnose:business-hours
                          {--organization-id= : Organization ID to check}
                          {--did-number= : Specific DID number to check}
                          {--current-time= : Override current time (YYYY-MM-DD HH:MM:SS format)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose business hours routing configuration and identify issues';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $orgId = (int) $this->option('organization-id');
        $didNumber = $this->option('did-number');
        $currentTimeStr = $this->option('current-time');

        if (!$orgId) {
            $this->error('Organization ID is required. Use --organization-id=<id>');
            return 1;
        }

        // Parse current time override
        $currentTime = $currentTimeStr ? Carbon::createFromFormat('Y-m-d H:i:s', $currentTimeStr) : Carbon::now();

        $this->info("🔍 Diagnosing Business Hours Routing");
        $this->line("Organization ID: {$orgId}");
        $this->line("Current time: {$currentTime->format('Y-m-d H:i:s T')}");
        $this->line('');

        // Check specific DID or all DIDs
        if ($didNumber) {
            return $this->diagnoseDid($orgId, $didNumber, $currentTime);
        } else {
            return $this->diagnoseAllDids($orgId, $currentTime);
        }
    }

    private function diagnoseDid(int $orgId, string $didNumber, Carbon $currentTime): int
    {
        $this->info("📞 Checking DID: {$didNumber}");

        $did = DidNumber::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $orgId)
            ->where('phone_number', $didNumber)
            ->first();

        if (!$did) {
            $this->error("❌ DID {$didNumber} not found in organization {$orgId}");
            return 1;
        }

        if ($did->routing_type !== 'business_hours') {
            $this->warn("⚠️  DID {$didNumber} is not configured for business hours routing");
            $this->line("   Current routing type: {$did->routing_type}");
            return 0;
        }

        return $this->analyzeBusinessHoursRouting($did, $currentTime);
    }

    private function diagnoseAllDids(int $orgId, Carbon $currentTime): int
    {
        $dids = DidNumber::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $orgId)
            ->where('routing_type', 'business_hours')
            ->where('status', 'active')
            ->get();

        if ($dids->isEmpty()) {
            $this->warn("⚠️  No active DIDs configured for business hours routing in organization {$orgId}");
            return 0;
        }

        $this->info("📋 Found {$dids->count()} DID(s) with business hours routing:");

        $hasIssues = false;
        foreach ($dids as $did) {
            $result = $this->analyzeBusinessHoursRouting($did, $currentTime);
            if ($result !== 0) {
                $hasIssues = true;
            }
            $this->line('');
        }

        return $hasIssues ? 1 : 0;
    }

    private function analyzeBusinessHoursRouting(DidNumber $did, Carbon $currentTime): int
    {
        $this->line("DID: {$did->phone_number} (ID: {$did->id})");

        // Get business hours schedule
        $scheduleId = $did->getTargetBusinessHoursId();
        if (!$scheduleId) {
            $this->error("❌ No business hours schedule ID found in routing config");
            $this->line("   Routing config: " . json_encode($did->routing_config));
            return 1;
        }

        $schedule = BusinessHoursSchedule::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $did->organization_id)
            ->where('id', $scheduleId)
            ->first();

        if (!$schedule) {
            $this->error("❌ Business hours schedule {$scheduleId} not found");
            return 1;
        }

        $this->line("Schedule: {$schedule->name} (ID: {$schedule->id})");
        $this->line("Status: {$schedule->status->value}");

        if ($schedule->status !== \App\Enums\BusinessHoursStatus::ACTIVE) {
            $this->error("❌ Business hours schedule is not active");
            return 1;
        }

        // Check if currently open
        $isOpen = $schedule->isCurrentlyOpen($currentTime);
        $this->line("Currently open: " . ($isOpen ? '✅ Yes' : '❌ No'));

        // Get current routing
        $routingType = $schedule->getCurrentRoutingType($currentTime);
        $targetId = $schedule->getCurrentRoutingTargetId($currentTime);

        $this->line("Routing type: {$routingType->value}");
        $this->line("Target ID: " . ($targetId ?? 'null'));

        // Analyze target ID format
        if (!$targetId) {
            $this->error("❌ No target ID configured for " . ($isOpen ? 'open' : 'closed') . " hours");
            $this->showRoutingConfig($schedule, $isOpen);
            return 1;
        }

        // Check target ID format
        $formatIssues = $this->checkTargetIdFormat($targetId, $routingType);
        if (!empty($formatIssues)) {
            foreach ($formatIssues as $issue) {
                $this->error("❌ {$issue}");
            }
            return 1;
        }

        // Check if target exists
        $existenceIssues = $this->checkTargetExists($targetId, $routingType, $did->organization_id);
        if (!empty($existenceIssues)) {
            foreach ($existenceIssues as $issue) {
                $this->error("❌ {$issue}");
            }
            return 1;
        }

        $this->info("✅ Business hours routing configuration is valid");

        return 0;
    }

    private function showRoutingConfig(BusinessHoursSchedule $schedule, bool $isOpen): void
    {
        $action = $isOpen ? $schedule->open_hours_action : $schedule->closed_hours_action;
        $actionType = $isOpen ? $schedule->open_hours_action_type : $schedule->closed_hours_action_type;

        $this->line("Action config: " . json_encode($action));
        $this->line("Action type: " . ($actionType?->value ?? 'null'));
    }

    private function checkTargetIdFormat(string $targetId, \App\Enums\BusinessHoursActionType $routingType): array
    {
        $issues = [];

        switch ($routingType) {
            case \App\Enums\BusinessHoursActionType::EXTENSION:
                if (!preg_match('/^ext-\d+$/', $targetId)) {
                    $issues[] = "Extension target ID '{$targetId}' should be in format 'ext-{id}' (e.g., 'ext-13')";
                }
                break;

            case \App\Enums\BusinessHoursActionType::RING_GROUP:
                if (!preg_match('/^rg-\d+$/', $targetId)) {
                    $issues[] = "Ring group target ID '{$targetId}' should be in format 'rg-{id}' (e.g., 'rg-5')";
                }
                break;

            case \App\Enums\BusinessHoursActionType::CONFERENCE_ROOM:
                if (!preg_match('/^conf-\d+$/', $targetId)) {
                    $issues[] = "Conference room target ID '{$targetId}' should be in format 'conf-{id}' (e.g., 'conf-1')";
                }
                break;

            case \App\Enums\BusinessHoursActionType::IVR_MENU:
                if (!preg_match('/^ivr-\d+$/', $targetId)) {
                    $issues[] = "IVR menu target ID '{$targetId}' should be in format 'ivr-{id}' (e.g., 'ivr-1')";
                }
                break;

            case \App\Enums\BusinessHoursActionType::HANGUP:
                // No target ID needed for hangup
                break;

            default:
                $issues[] = "Unknown routing type: {$routingType->value}";
        }

        return $issues;
    }

    private function checkTargetExists(string $targetId, \App\Enums\BusinessHoursActionType $routingType, int $orgId): array
    {
        $issues = [];

        switch ($routingType) {
            case \App\Enums\BusinessHoursActionType::EXTENSION:
                if (preg_match('/^ext-(\d+)$/', $targetId, $matches)) {
                    $extensionId = (int) $matches[1];
                    $extension = \App\Models\Extension::withoutGlobalScope(OrganizationScope::class)
                        ->where('id', $extensionId)
                        ->where('organization_id', $orgId)
                        ->first();

                    if (!$extension) {
                        $issues[] = "Extension with ID {$extensionId} not found";
                    } elseif (!$extension->isActive()) {
                        $issues[] = "Extension {$extension->extension_number} is not active";
                    }
                }
                break;

            case \App\Enums\BusinessHoursActionType::RING_GROUP:
                if (preg_match('/^rg-(\d+)$/', $targetId, $matches)) {
                    $ringGroupId = (int) $matches[1];
                    $ringGroup = \App\Models\RingGroup::withoutGlobalScope(OrganizationScope::class)
                        ->where('id', $ringGroupId)
                        ->where('organization_id', $orgId)
                        ->first();

                    if (!$ringGroup) {
                        $issues[] = "Ring group with ID {$ringGroupId} not found";
                    } elseif ($ringGroup->status !== 'active') {
                        $issues[] = "Ring group '{$ringGroup->name}' is not active";
                    }
                }
                break;

            case \App\Enums\BusinessHoursActionType::CONFERENCE_ROOM:
                if (preg_match('/^conf-(\d+)$/', $targetId, $matches)) {
                    $conferenceRoomId = (int) $matches[1];
                    $conferenceRoom = \App\Models\ConferenceRoom::withoutGlobalScope(OrganizationScope::class)
                        ->where('id', $conferenceRoomId)
                        ->where('organization_id', $orgId)
                        ->first();

                    if (!$conferenceRoom) {
                        $issues[] = "Conference room with ID {$conferenceRoomId} not found";
                    }
                }
                break;

            case \App\Enums\BusinessHoursActionType::IVR_MENU:
                if (preg_match('/^ivr-(\d+)$/', $targetId, $matches)) {
                    $ivrMenuId = (int) $matches[1];
                    $ivrMenu = \App\Models\IvrMenu::withoutGlobalScope(OrganizationScope::class)
                        ->where('id', $ivrMenuId)
                        ->where('organization_id', $orgId)
                        ->first();

                    if (!$ivrMenu) {
                        $issues[] = "IVR menu with ID {$ivrMenuId} not found";
                    } elseif ($ivrMenu->status !== 'active') {
                        $issues[] = "IVR menu '{$ivrMenu->name}' is not active";
                    }
                }
                break;
        }

        return $issues;
    }
}
