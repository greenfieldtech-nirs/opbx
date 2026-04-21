<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UserStatus;
use App\Http\Controllers\Traits\AppliesFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class AppliesFiltersTraitTest extends TestCase
{
    use AppliesFilters;

    public function test_applies_enum_filter_with_valid_value(): void
    {
        $query = Mockery::mock(Builder::class);
        $request = Request::create('/', 'GET', ['status' => 'active']);

        $query->shouldReceive('withStatus')
            ->once()
            ->with(UserStatus::ACTIVE)
            ->andReturnSelf();

        $config = [
            'status' => [
                'type' => 'enum',
                'enum' => UserStatus::class,
                'scope' => 'withStatus'
            ]
        ];

        $result = $this->applyFilters($query, $request, $config);

        $this->assertSame($query, $result);
    }

    public function test_applies_enum_filter_with_invalid_value_ignores_filter(): void
    {
        $query = Mockery::mock(Builder::class);
        $request = Request::create('/', 'GET', ['status' => 'invalid']);

        // Should not call withStatus since enum is invalid
        $query->shouldNotReceive('withStatus');

        $config = [
            'status' => [
                'type' => 'enum',
                'enum' => UserStatus::class,
                'scope' => 'withStatus'
            ]
        ];

        $result = $this->applyFilters($query, $request, $config);

        $this->assertSame($query, $result);
    }

    public function test_applies_search_filter_with_filled_value(): void
    {
        $query = Mockery::mock(Builder::class);
        $request = Request::create('/', 'GET', ['search' => 'test']);

        $query->shouldReceive('search')
            ->once()
            ->with('test')
            ->andReturnSelf();

        $config = [
            'search' => [
                'type' => 'search',
                'scope' => 'search'
            ]
        ];

        $result = $this->applyFilters($query, $request, $config);

        $this->assertSame($query, $result);
    }

    public function test_applies_search_filter_with_empty_value_ignores_filter(): void
    {
        $query = Mockery::mock(Builder::class);
        $request = Request::create('/', 'GET', ['search' => '']);

        // Should not call search since value is empty
        $query->shouldNotReceive('search');

        $config = [
            'search' => [
                'type' => 'search',
                'scope' => 'search'
            ]
        ];

        $result = $this->applyFilters($query, $request, $config);

        $this->assertSame($query, $result);
    }

    public function test_applies_column_filter_with_scope(): void
    {
        $query = Mockery::mock(Builder::class);
        $request = Request::create('/', 'GET', ['user_id' => '123']);

        $query->shouldReceive('forUser')
            ->once()
            ->with('123')
            ->andReturnSelf();

        $config = [
            'user_id' => [
                'type' => 'column',
                'scope' => 'forUser',
                'require_filled' => true
            ]
        ];

        $result = $this->applyFilters($query, $request, $config);

        $this->assertSame($query, $result);
    }

    public function test_applies_column_filter_without_scope_uses_where(): void
    {
        $query = Mockery::mock(Builder::class);
        $request = Request::create('/', 'GET', ['category' => 'test']);

        $query->shouldReceive('where')
            ->once()
            ->with('category', '=', 'test')
            ->andReturnSelf();

        $config = [
            'category' => [
                'type' => 'column'
            ]
        ];

        $result = $this->applyFilters($query, $request, $config);

        $this->assertSame($query, $result);
    }

    public function test_multiple_filters_applied_correctly(): void
    {
        $query = Mockery::mock(Builder::class);
        $request = Request::create('/', 'GET', [
            'status' => 'active',
            'search' => 'test',
            'category' => 'example'
        ]);

        $query->shouldReceive('withStatus')
            ->once()
            ->with(UserStatus::ACTIVE)
            ->andReturnSelf();

        $query->shouldReceive('search')
            ->once()
            ->with('test')
            ->andReturnSelf();

        $query->shouldReceive('where')
            ->once()
            ->with('category', '=', 'example')
            ->andReturnSelf();

        $config = [
            'status' => [
                'type' => 'enum',
                'enum' => UserStatus::class,
                'scope' => 'withStatus'
            ],
            'search' => [
                'type' => 'search',
                'scope' => 'search'
            ],
            'category' => [
                'type' => 'column'
            ]
        ];

        $result = $this->applyFilters($query, $request, $config);

        $this->assertSame($query, $result);
    }
}