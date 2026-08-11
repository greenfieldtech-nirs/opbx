<?php

declare(strict_types=1);

namespace App\Http\Requests\Supervisor;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role?->canAssignSupervisors() ?? false;
    }

    public function rules(): array
    {
        return [
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'ring_group_ids' => ['present', 'array'],
            'ring_group_ids.*' => ['integer', 'exists:ring_groups,id'],
        ];
    }
}
