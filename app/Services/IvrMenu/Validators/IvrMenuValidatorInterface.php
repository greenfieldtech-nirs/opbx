<?php

declare(strict_types=1);

namespace App\Services\IvrMenu\Validators;

use Illuminate\Validation\Validator;

/**
 * Interface for IVR menu validation strategies.
 */
interface IvrMenuValidatorInterface
{
    /**
     * Validate a specific aspect of IVR menu data.
     *
     * @param Validator $validator The Laravel validator instance
     * @param array $data The IVR menu data being validated
     * @param int|null $excludeMenuId Menu ID to exclude from validation
     */
    public function validate(Validator $validator, array $data, ?int $excludeMenuId = null): void;
}