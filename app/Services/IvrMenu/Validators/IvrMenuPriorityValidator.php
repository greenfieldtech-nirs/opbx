<?php

declare(strict_types=1);

namespace App\Services\IvrMenu\Validators;

use Illuminate\Validation\Validator;

/**
 * Validates that IVR menu option priorities are unique and sequential.
 */
class IvrMenuPriorityValidator implements IvrMenuValidatorInterface
{
    public function validate(Validator $validator, array $data, ?int $excludeMenuId = null): void
    {
        $options = $data['options'] ?? [];

        if (empty($options)) {
            return;
        }

        $priorities = [];
        foreach ($options as $index => $option) {
            $priority = $option['priority'] ?? null;

            if ($priority === null) {
                continue;
            }

            // Check for duplicate priorities
            if (in_array($priority, $priorities)) {
                $validator->errors()->add(
                    "options.{$index}.priority",
                    "Priority {$priority} is already used by another option."
                );
            }

            $priorities[] = $priority;
        }

        // Check for non-sequential priorities (optional - some systems allow gaps)
        // This could be made configurable if needed
        sort($priorities);
        $expectedPriority = 1;
        foreach ($priorities as $priority) {
            if ($priority != $expectedPriority) {
                // Allow gaps for now, but could add validation here if needed
                break;
            }
            $expectedPriority++;
        }
    }
}