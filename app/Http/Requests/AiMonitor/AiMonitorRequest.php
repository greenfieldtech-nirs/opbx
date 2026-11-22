<?php

declare(strict_types=1);

namespace App\Http\Requests\AiMonitor;

use Illuminate\Foundation\Http\FormRequest;

class AiMonitorRequest extends FormRequest
{
    /** The monitor looks back from 1 minute to 12 hours; 60 minutes by default. */
    public const DEFAULT_MINUTES = 60;

    public const MAX_MINUTES = 720;

    public function rules(): array
    {
        return [
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_MINUTES],
        ];
    }

    public function minutes(): int
    {
        return (int) $this->validated('minutes', self::DEFAULT_MINUTES);
    }
}
