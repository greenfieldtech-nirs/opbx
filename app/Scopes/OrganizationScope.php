<?php

declare(strict_types=1);

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class OrganizationScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = $this->getOrganizationId();

        if ($organizationId !== null) {
            $builder->where($model->getTable() . '.organization_id', $organizationId);
        }
    }

    /**
     * Get the current organization ID from the authenticated user.
     */
    protected function getOrganizationId(): ?int
    {
        $user = auth()->user();

        if ($user && isset($user->organization_id)) {
            return (int) $user->organization_id;
        }

        return null;
    }
}
