<?php

declare(strict_types=1);

namespace App\Http\Requests\CallQueue;

use Illuminate\Validation\Rule;

/**
 * Form request validator for updating an existing call queue.
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
        $rules = parent::rules();

        $queueId = $this->route('call_queue');

        $rules['name'] = [
            'required',
            'string',
            'min:2',
            'max:100',
            Rule::unique('call_queues', 'name')
                ->where(fn ($query) => $query->where('organization_id', $this->user()->organization_id))
                ->ignore($queueId),
        ];

        return $rules;
    }
}
