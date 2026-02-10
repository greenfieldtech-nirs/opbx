<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\AlbsStatus;
use App\Enums\AlbsStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ALBS enums.
 */
class AlbsEnumsTest extends TestCase
{
    /**
     * Test AlbsStrategy enum values.
     */
    public function test_albs_strategy_enum_values(): void
    {
        $this->assertEquals('round_robin', AlbsStrategy::ROUND_ROBIN->value);
        $this->assertEquals('priority', AlbsStrategy::PRIORITY->value);
        $this->assertEquals('percentage', AlbsStrategy::PERCENTAGE->value);
    }

    /**
     * Test AlbsStrategy label method.
     */
    public function test_albs_strategy_label(): void
    {
        $this->assertEquals('Round Robin', AlbsStrategy::ROUND_ROBIN->label());
        $this->assertEquals('Priority Based', AlbsStrategy::PRIORITY->label());
        $this->assertEquals('Percentage Based', AlbsStrategy::PERCENTAGE->label());
    }

    /**
     * Test AlbsStrategy description method.
     */
    public function test_albs_strategy_description(): void
    {
        $this->assertEquals(
            'Distribute calls evenly across all AI assistants in sequence',
            AlbsStrategy::ROUND_ROBIN->description()
        );
        $this->assertEquals(
            'Always route to highest priority (lowest number) AI assistant',
            AlbsStrategy::PRIORITY->description()
        );
        $this->assertEquals(
            'Route based on configured weight percentages',
            AlbsStrategy::PERCENTAGE->description()
        );
    }

    /**
     * Test AlbsStatus enum values.
     */
    public function test_albs_status_enum_values(): void
    {
        $this->assertEquals('active', AlbsStatus::ACTIVE->value);
        $this->assertEquals('inactive', AlbsStatus::INACTIVE->value);
    }

    /**
     * Test AlbsStatus label method.
     */
    public function test_albs_status_label(): void
    {
        $this->assertEquals('Active', AlbsStatus::ACTIVE->label());
        $this->assertEquals('Inactive', AlbsStatus::INACTIVE->label());
    }

    /**
     * Test AlbsStatus description method.
     */
    public function test_albs_status_description(): void
    {
        $this->assertEquals(
            'Load balancer is active and can distribute calls',
            AlbsStatus::ACTIVE->description()
        );
        $this->assertEquals(
            'Load balancer is temporarily disabled',
            AlbsStatus::INACTIVE->description()
        );
    }

    /**
     * Test AlbsStrategy cases method returns all cases.
     */
    public function test_albs_strategy_cases(): void
    {
        $cases = AlbsStrategy::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(AlbsStrategy::ROUND_ROBIN, $cases);
        $this->assertContains(AlbsStrategy::PRIORITY, $cases);
        $this->assertContains(AlbsStrategy::PERCENTAGE, $cases);
    }

    /**
     * Test AlbsStatus cases method returns all cases.
     */
    public function test_albs_status_cases(): void
    {
        $cases = AlbsStatus::cases();

        $this->assertCount(2, $cases);
        $this->assertContains(AlbsStatus::ACTIVE, $cases);
        $this->assertContains(AlbsStatus::INACTIVE, $cases);
    }

    /**
     * Test AlbsStrategy can be created from value.
     */
    public function test_albs_strategy_from_value(): void
    {
        $strategy = AlbsStrategy::from('round_robin');
        $this->assertEquals(AlbsStrategy::ROUND_ROBIN, $strategy);

        $strategy = AlbsStrategy::from('priority');
        $this->assertEquals(AlbsStrategy::PRIORITY, $strategy);

        $strategy = AlbsStrategy::from('percentage');
        $this->assertEquals(AlbsStrategy::PERCENTAGE, $strategy);
    }

    /**
     * Test AlbsStatus can be created from value.
     */
    public function test_albs_status_from_value(): void
    {
        $status = AlbsStatus::from('active');
        $this->assertEquals(AlbsStatus::ACTIVE, $status);

        $status = AlbsStatus::from('inactive');
        $this->assertEquals(AlbsStatus::INACTIVE, $status);
    }

    /**
     * Test AlbsStrategy tryFrom returns null for invalid value.
     */
    public function test_albs_strategy_try_from_invalid(): void
    {
        $this->assertNull(AlbsStrategy::tryFrom('invalid'));
        $this->assertNull(AlbsStrategy::tryFrom('simultaneous'));
    }

    /**
     * Test AlbsStatus tryFrom returns null for invalid value.
     */
    public function test_albs_status_try_from_invalid(): void
    {
        $this->assertNull(AlbsStatus::tryFrom('invalid'));
        $this->assertNull(AlbsStatus::tryFrom('pending'));
    }
}
