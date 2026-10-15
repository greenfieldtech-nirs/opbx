<?php

declare(strict_types=1);

namespace App\Http\Requests\Supervisor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'user_ids.*' => ['integer', Rule::exists('users', 'id')->where('organization_id', $this->user()?->organization_id)],
            'ring_group_ids' => ['present', 'array'],
            'ring_group_ids.*' => ['integer', Rule::exists('ring_groups', 'id')->where('organization_id', $this->user()?->organization_id)],
        ];
    }
}
