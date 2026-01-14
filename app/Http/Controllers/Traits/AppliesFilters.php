<?php

declare(strict_types=1);

namespace App\Http\Controllers\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * AppliesFilters Trait
 *
 * Provides a standardized way to apply filters to Eloquent queries in API controllers.
 * Supports enum filters, search filters, and column filters with proper validation.
 *
 * Filter configuration example:
 * [
 *     'type' => [
 *         'type' => 'enum',
 *         'enum' => ExtensionType::class,
 *         'scope' => 'withType'
 *     ],
 *     'status' => [
 *         'type' => 'enum',
 *         'enum' => UserStatus::class,
 *         'scope' => 'withStatus'
 *     ],
 *     'search' => [
 *         'type' => 'search',
 *         'scope' => 'search'
 *     ],
 *     'user_id' => [
 *         'type' => 'column',
 *         'scope' => 'forUser',
 *         'require_filled' => true
 *     ]
 * ]
 */
trait AppliesFilters
{
    /**
     * Apply filters to an Eloquent query based on the provided configuration.
     *
     * @param Builder $query The query builder to apply filters to
     * @param Request $request The HTTP request containing filter parameters
     * @param array $filterConfig Configuration array defining how to apply filters
     * @return Builder The modified query builder
     */
    protected function applyFilters(Builder $query, Request $request, array $filterConfig): Builder
    {
        foreach ($filterConfig as $filterName => $config) {
            $filterType = $config['type'] ?? 'column';

            switch ($filterType) {
                case 'enum':
                    $this->applyEnumFilter($query, $request, $filterName, $config);
                    break;

                case 'search':
                    $this->applySearchFilter($query, $request, $filterName, $config);
                    break;

                case 'column':
                default:
                    $this->applyColumnFilter($query, $request, $filterName, $config);
                    break;
            }
        }

        return $query;
    }

    /**
     * Apply an enum-based filter with validation.
     *
     * @param Builder $query
     * @param Request $request
     * @param string $filterName
     * @param array $config
     */
    private function applyEnumFilter(Builder $query, Request $request, string $filterName, array $config): void
    {
        if (!$request->has($filterName)) {
            return;
        }

        $enumClass = $config['enum'] ?? null;
        $scopeMethod = $config['scope'] ?? null;

        if (!$enumClass || !$scopeMethod) {
            return;
        }

        $value = $request->input($filterName);
        $enum = $enumClass::tryFrom($value);

        if ($enum) {
            $query->{$scopeMethod}($enum);
        }
    }

    /**
     * Apply a search filter with filled() validation.
     *
     * @param Builder $query
     * @param Request $request
     * @param string $filterName
     * @param array $config
     */
    private function applySearchFilter(Builder $query, Request $request, string $filterName, array $config): void
    {
        if (!$request->has($filterName) || !$request->filled($filterName)) {
            return;
        }

        $scopeMethod = $config['scope'] ?? 'search';

        $query->{$scopeMethod}($request->input($filterName));
    }

    /**
     * Apply a column-based filter with optional filled() validation.
     *
     * @param Builder $query
     * @param Request $request
     * @param string $filterName
     * @param array $config
     */
    private function applyColumnFilter(Builder $query, Request $request, string $filterName, array $config): void
    {
        $requireFilled = $config['require_filled'] ?? false;

        if (!$request->has($filterName)) {
            return;
        }

        if ($requireFilled && !$request->filled($filterName)) {
            return;
        }

        $scopeMethod = $config['scope'] ?? null;
        $operator = $config['operator'] ?? '=';

        if ($scopeMethod) {
            // Use scope method if specified
            $query->{$scopeMethod}($request->input($filterName));
        } else {
            // Use direct where clause
            $query->where($filterName, $operator, $request->input($filterName));
        }
    }
}