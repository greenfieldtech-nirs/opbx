<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A resource must be fully provisioned when it is created, so the 'unavailable' route is rejected on create
 * (POST). Updates may carry it: an existing resource can be unprovisioned (the user-delete flow sets it).
 * Used on every create request of the 7 resource types that can hold an 'unavailable' route; requests
 * shared by create and update (call queue, IVR menu) get the POST check from here.
 */
final class RejectUnavailableOnCreate implements ValidationRule
{
    public const VALUE = 'unavailable';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === self::VALUE && request()->isMethod('POST')) {
            $fail('Choose a destination.');
        }
    }
}
