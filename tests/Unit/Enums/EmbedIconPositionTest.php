<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\EmbedIconPosition;
use PHPUnit\Framework\TestCase;

final class EmbedIconPositionTest extends TestCase
{
    public function test_has_four_corner_cases(): void
    {
        $values = array_map(fn ($c) => $c->value, EmbedIconPosition::cases());
        sort($values);
        $this->assertSame(['bottom-left', 'bottom-right', 'top-left', 'top-right'], $values);
    }

    public function test_default_is_bottom_right(): void
    {
        $this->assertSame(EmbedIconPosition::BOTTOM_RIGHT, EmbedIconPosition::default());
    }
}
