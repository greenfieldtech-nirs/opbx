<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Services\AutoDialer\MetadataHelper;
use PHPUnit\Framework\TestCase;

class MetadataHelperTest extends TestCase
{
    public function test_flattens_nested_metadata(): void
    {
        $metadata = [
            'key' => 'value',
            'nested' => [
                'child' => 'childValue',
            ],
        ];

        $result = MetadataHelper::flatten($metadata);

        $this->assertSame([
            'key' => 'value',
            'nested.child' => 'childValue',
        ], $result);
    }

    public function test_serializes_booleans_and_numbers(): void
    {
        $result = MetadataHelper::flatten([
            'flag' => true,
            'count' => 42,
            'price' => 19.99,
            'empty' => false,
        ]);

        $this->assertSame([
            'flag' => 'true',
            'count' => '42',
            'price' => '19.99',
            'empty' => 'false',
        ], $result);
    }

    public function test_returns_empty_array_for_empty_input(): void
    {
        $this->assertSame([], MetadataHelper::flatten([]));
        $this->assertSame([], MetadataHelper::flatten(null ?? []));
    }

    public function test_builds_sip_headers_with_x_prefix(): void
    {
        $headers = MetadataHelper::toSipHeaders([
            'key' => 'value',
            'X-Already' => 'prefixed',
        ]);

        $this->assertSame([
            'X-key' => 'value',
            'X-Already' => 'prefixed',
        ], $headers);
    }
}
