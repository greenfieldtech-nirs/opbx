<?php

namespace Database\Seeders;

use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\RingGroup;
use Illuminate\Database\Seeder;

class FixMissingRingGroupAndIvrMenuSeeder extends Seeder
{
    /**
     * Run the database seeder to create missing ring groups and IVR menus
     * referenced by extensions 3001 and 3002.
     */
    public function run(): void
    {
        // Get the first organization (assuming single-tenant for now)
        $organization = Organization::first();

        if (!$organization) {
            $this->command->error('No organization found. Please run AdminUserSeeder first.');
            return;
        }

        $this->command->info("Using organization: {$organization->name} (ID: {$organization->id})");

        // Find extensions 3001 and 3002
        $extension3001 = Extension::where('extension_number', '3001')->first();
        $extension3002 = Extension::where('extension_number', '3002')->first();

        // Create ring group for extension 3001 if it doesn't exist
        if ($extension3001) {
            $this->command->info("Found extension 3001, checking ring group...");

            $ringGroupId = $extension3001->configuration['ring_group_id'] ?? null;
            $ringGroup = $ringGroupId ? RingGroup::find($ringGroupId) : null;

            if (!$ringGroup) {
                $this->command->info("Creating test ring group for extension 3001...");

                $ringGroup = RingGroup::create([
                    'organization_id' => $organization->id,
                    'name' => 'Test Ring Group',
                    'status' => 'active',
                    'strategy' => 'simultaneous',
                    'timeout' => 20,
                ]);

                // Add a member to the ring group (use extension 3000 if it exists, otherwise create one)
                $memberExtension = Extension::where('organization_id', $organization->id)
                    ->where('extension_number', '3000')
                    ->first();

                if (!$memberExtension) {
                    $memberExtension = Extension::where('organization_id', $organization->id)->first();
                }

                if ($memberExtension) {
                    $ringGroup->members()->create([
                        'extension_id' => $memberExtension->id,
                        'priority' => 1,
                    ]);
                    $this->command->info("Added extension {$memberExtension->extension_number} as ring group member");
                }

                // Update extension 3001 configuration
                $extension3001->update([
                    'configuration' => array_merge($extension3001->configuration ?? [], [
                        'ring_group_id' => $ringGroup->id
                    ])
                ]);

                $this->command->info("Updated extension 3001 to reference ring group ID: {$ringGroup->id}");
            } else {
                $this->command->info("Extension 3001 already references existing ring group: {$ringGroup->name}");
            }
        }

        // Create IVR menu for extension 3002 if it doesn't exist
        if ($extension3002) {
            $this->command->info("Found extension 3002, checking IVR menu...");

            $ivrId = $extension3002->configuration['ivr_id'] ?? null;
            $ivrMenu = $ivrId ? IvrMenu::find($ivrId) : null;

            if (!$ivrMenu) {
                $this->command->info("Creating test IVR menu for extension 3002...");

                $ivrMenu = IvrMenu::create([
                    'organization_id' => $organization->id,
                    'name' => 'Test IVR Menu',
                    'status' => 'active',
                    'tts_text' => 'Welcome to our IVR system',
                    'max_turns' => 3,
                ]);

                // Update extension 3002 configuration
                $extension3002->update([
                    'configuration' => array_merge($extension3002->configuration ?? [], [
                        'ivr_id' => $ivrMenu->id
                    ])
                ]);

                $this->command->info("Updated extension 3002 to reference IVR menu ID: {$ivrMenu->id}");
            } else {
                $this->command->info("Extension 3002 already references existing IVR menu: {$ivrMenu->name}");
            }
        }

        if (!$extension3001 && !$extension3002) {
            $this->command->warn("Extensions 3001 and 3002 not found. This seeder is designed to fix references for existing extensions.");
        }

        $this->command->info("Seeder completed successfully!");
    }
}
