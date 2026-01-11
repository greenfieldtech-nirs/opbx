<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\BusinessHoursSchedule;

try {
    $schedule = BusinessHoursSchedule::find(1);

    if (!$schedule) {
        echo "Business hours schedule with ID 1 not found.\n";
        exit(1);
    }

    echo "Business Hours Schedule ID 1 Configuration:\n";
    echo "==========================================\n\n";

    echo "Basic Info:\n";
    echo "- ID: {$schedule->id}\n";
    echo "- Organization ID: {$schedule->organization_id}\n";
    echo "- Name: {$schedule->name}\n";
    echo "- Status: {$schedule->status->value}\n";
    echo "- Created: {$schedule->created_at}\n";
    echo "- Updated: {$schedule->updated_at}\n\n";

    echo "Open Hours Configuration:\n";
    echo "- Action Type: {$schedule->open_hours_action_type->value}\n";
    echo "- Action JSON: " . json_encode($schedule->open_hours_action, JSON_PRETTY_PRINT) . "\n\n";

    echo "Closed Hours Configuration:\n";
    echo "- Action Type: {$schedule->closed_hours_action_type->value}\n";
    echo "- Action JSON: " . json_encode($schedule->closed_hours_action, JSON_PRETTY_PRINT) . "\n\n";

    echo "Schedule Days:\n";
    foreach ($schedule->scheduleDays as $day) {
        $dayName = $day->day_of_week->name;
        $enabled = $day->enabled ? 'Enabled' : 'Disabled';
        echo "- {$dayName}: {$enabled}\n";

        if ($day->enabled && $day->timeRanges->count() > 0) {
            foreach ($day->timeRanges as $range) {
                echo "  - {$range->start_time} to {$range->end_time}\n";
            }
        }
    }

    echo "\nExceptions:\n";
    if ($schedule->exceptions->count() > 0) {
        foreach ($schedule->exceptions as $exception) {
            echo "- Date: {$exception->date}, Is Open: " . ($exception->is_open ? 'Yes' : 'No') . "\n";
            if ($exception->timeRanges->count() > 0) {
                foreach ($exception->timeRanges as $range) {
                    echo "  - {$range->start_time} to {$range->end_time}\n";
                }
            }
        }
    } else {
        echo "- No exceptions configured\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}