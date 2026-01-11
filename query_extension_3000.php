<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Enums\ExtensionType;
use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    // Run migrations to create tables
    echo "Running migrations...\n";
    Artisan::call('migrate:fresh', ['--force' => true]);
    echo "Migrations completed.\n";
    // Create test organization
    $organization = Organization::factory()->create(['status' => 'active']);
    echo "Created organization: {$organization->id}\n";

    // Create user for extension
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'status' => 'active',
    ]);

    // Create conference room
    $conferenceRoom = ConferenceRoom::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Test Conference Room',
        'max_participants' => 10,
        'status' => 'active',
    ]);
    echo "Created conference room: {$conferenceRoom->id}\n";

    // Create extension 3000 as conference room
    $extension = Extension::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'extension_number' => '3000',
        'type' => ExtensionType::CONFERENCE,
        'status' => 'active',
        'configuration' => [
            'conference_room_id' => $conferenceRoom->id,
        ],
    ]);
    echo "Created extension 3000: {$extension->id}\n";

    // Query and display the configuration
    $ext3000 = Extension::where('extension_number', '3000')->first();
    if ($ext3000) {
        echo "\nExtension 3000 configuration JSON:\n";
        echo json_encode($ext3000->configuration, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "Extension 3000 not found\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}