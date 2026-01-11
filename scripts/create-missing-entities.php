<?php

/**
 * Script to create missing ring groups and IVR menus referenced by extensions 3001 and 3002
 * This follows the same pattern as the test-voice-routing.php script
 *
 * Usage: php scripts/create-missing-entities.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Enums\ExtensionType;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\Organization;
use App\Models\RingGroup;
use Illuminate\Foundation\Application;

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Creating missing entities for extensions 3001 and 3002...\n";

try {
    // Get the first organization
    $organization = Organization::first();
    if (!$organization) {
        echo "No organization found. Please run database seeders first.\n";
        exit(1);
    }

    echo "Using organization: {$organization->name} (ID: {$organization->id})\n";

    // Check for extension 3001 and create ring group if needed
    $extension3001 = Extension::where('extension_number', '3001')->first();
    if ($extension3001 && $extension3001->type === ExtensionType::RING_GROUP) {
        $ringGroupId = $extension3001->configuration['ring_group_id'] ?? null;
        if (!$ringGroupId || !RingGroup::find($ringGroupId)) {
            echo "Creating ring group for extension 3001...\n";

            $ringGroup = RingGroup::create([
                'organization_id' => $organization->id,
                'name' => 'Test Ring Group',
                'status' => 'active',
                'strategy' => 'simultaneous',
                'timeout' => 20,
            ]);

            // Find an existing extension to add as a member
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
                echo "Added extension {$memberExtension->extension_number} as ring group member\n";
            }

            $extension3001->update([
                'configuration' => array_merge($extension3001->configuration ?? [], [
                    'ring_group_id' => $ringGroup->id
                ])
            ]);

            echo "Created ring group: {$ringGroup->name} (ID: {$ringGroup->id})\n";
            echo "Updated extension 3001 to reference ring group ID: {$ringGroup->id}\n";
        } else {
            echo "Extension 3001 already has a valid ring group reference\n";
        }
    } else {
        echo "Extension 3001 not found or not a ring group type\n";
    }

    // Check for extension 3002 and create IVR menu if needed
    $extension3002 = Extension::where('extension_number', '3002')->first();
    if ($extension3002 && $extension3002->type === ExtensionType::IVR) {
        $ivrId = $extension3002->configuration['ivr_id'] ?? null;
        if (!$ivrId || !IvrMenu::find($ivrId)) {
            echo "Creating IVR menu for extension 3002...\n";

            $ivrMenu = IvrMenu::create([
                'organization_id' => $organization->id,
                'name' => 'Test IVR Menu',
                'status' => 'active',
                'tts_text' => 'Welcome to our IVR system',
                'max_turns' => 3,
            ]);

            $extension3002->update([
                'configuration' => array_merge($extension3002->configuration ?? [], [
                    'ivr_id' => $ivrMenu->id
                ])
            ]);

            echo "Created IVR menu: {$ivrMenu->name} (ID: {$ivrMenu->id})\n";
            echo "Updated extension 3002 to reference IVR menu ID: {$ivrMenu->id}\n";
        } else {
            echo "Extension 3002 already has a valid IVR menu reference\n";
        }
    } else {
        echo "Extension 3002 not found or not an IVR type\n";
    }

    echo "\nScript completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}