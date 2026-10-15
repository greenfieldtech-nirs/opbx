<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * "Unprovisioned" = a resource saved while one of its routes is 'unavailable' (provisioned for future use).
 * Each model lists its route columns in unprovisionedColumns(); that is the single definition used by the
 * `unprovisioned` scope (list filter `?unprovisioned=1`) and the `is_unprovisioned` attribute (API badge).
 */
trait HasUnprovisionedState
{
    public const UNPROVISIONED_VALUE = 'unavailable';

    /** @return list<string> columns whose value 'unavailable' marks the resource unprovisioned */
    abstract protected function unprovisionedColumns(): array;

    public function scopeUnprovisioned(Builder $query, mixed $on = true): Builder
    {
        if (! filter_var($on, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        return $query->where(function (Builder $q): void {
            foreach ($this->unprovisionedColumns() as $column) {
                $q->orWhere($this->qualifyColumn($column), self::UNPROVISIONED_VALUE);
            }
            $this->unprovisionedExtraConstraint($q);
        });
    }

    /** Hook for models whose routes live in a related table (IVR options). */
    protected function unprovisionedExtraConstraint(Builder $query): void {}

    protected function isUnprovisioned(): Attribute
    {
        return Attribute::get(function (): bool {
            foreach ($this->unprovisionedColumns() as $column) {
                if (($this->getAttributes()[$column] ?? null) === self::UNPROVISIONED_VALUE) {
                    return true;
                }
            }

            return $this->hasUnprovisionedRelatedRoute();
        });
    }

    protected function hasUnprovisionedRelatedRoute(): bool
    {
        return false;
    }
}
