<?php

declare(strict_types=1);

namespace App\Http\Requests\CallQueue;

use App\Models\CallQueue;
use Illuminate\Validation\Rule;

/**
 * Form request validator for updating an existing call queue.
 *
 * PUT and PATCH are partial: only the fields sent are validated and saved. No create defaults
 * are merged, and conditional rules use the stored value of a field the request leaves out.
 */
class UpdateCallQueueRequest extends StoreCallQueueRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $base = parent::rules();

        $rules['name'] = [
            'required',
            'string',
            'min:2',
            'max:100',
            Rule::unique('call_queues', 'name')
                ->where(fn ($query) => $query->where('organization_id', $this->user()->organization_id))
                ->ignore($this->storedQueue()?->id),
        ];

        foreach ($rules as $field => $fieldRules) {
            if (! str_contains($field, '.')) {
                $rules[$field] = ['sometimes', ...$fieldRules];
            }
        }

        // A target left out of the request may rely on the stored one, so these are not `sometimes`.
        foreach (self::FALLBACK_TARGETS as $action => [$field]) {
            $rules[$field] = [
                Rule::requiredIf(fn () => $this->fallbackValue('fallback_action') === $action && $this->fallbackValue($field) === null),
                ...array_slice($base[$field], 1),
            ];
        }

        return $rules;
    }

    /**
     * Fallback targets are re-checked only when the request touches the fallback.
     */
    protected function validateFallbackTargets($validator, int $organizationId): void
    {
        if ($this->hasAny(['fallback_action', ...array_column(self::FALLBACK_TARGETS, 0)])) {
            parent::validateFallbackTargets($validator, $organizationId);
        }
    }

    /**
     * The request value when sent, otherwise the stored one.
     */
    protected function fallbackValue(string $field): mixed
    {
        if ($this->has($field)) {
            return $this->input($field);
        }

        $stored = $this->storedQueue()?->{$field};

        return $stored instanceof \BackedEnum ? $stored->value : $stored;
    }

    private function storedQueue(): ?CallQueue
    {
        $queue = $this->route('call_queue');

        return $queue instanceof CallQueue ? $queue : ($queue ? CallQueue::find($queue) : null);
    }

    /**
     * No create defaults on update: an omitted field keeps its stored value.
     */
    protected function prepareForValidation(): void {}
}
