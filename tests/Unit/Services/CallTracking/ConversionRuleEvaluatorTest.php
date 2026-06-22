<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CallTracking;

use App\Services\CallTracking\ConversionRuleEvaluator;
use PHPUnit\Framework\TestCase;

class ConversionRuleEvaluatorTest extends TestCase
{
    private ConversionRuleEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new ConversionRuleEvaluator;
    }

    public function test_converts_when_answered_and_duration_exceeds_threshold(): void
    {
        $rule = [
            'min_answered_duration_seconds' => 60,
            'requires_answered_disposition' => true,
        ];

        $this->assertTrue($this->evaluator->evaluate($rule, 'CONNECTED', 90));
    }

    public function test_does_not_convert_when_duration_below_threshold(): void
    {
        $rule = [
            'min_answered_duration_seconds' => 60,
            'requires_answered_disposition' => true,
        ];

        $this->assertFalse($this->evaluator->evaluate($rule, 'CONNECTED', 30));
    }

    public function test_does_not_convert_when_not_answered(): void
    {
        $rule = [
            'min_answered_duration_seconds' => 0,
            'requires_answered_disposition' => true,
        ];

        $this->assertFalse($this->evaluator->evaluate($rule, 'NO ANSWER', 120));
    }

    public function test_default_rule_requires_answered_disposition(): void
    {
        $rule = [
            'min_answered_duration_seconds' => 0,
        ];

        $this->assertTrue($this->evaluator->evaluate($rule, 'CONNECTED', 0));
        $this->assertFalse($this->evaluator->evaluate($rule, 'BUSY', 0));
    }

    public function test_ignores_answered_requirement_when_disabled(): void
    {
        $rule = [
            'min_answered_duration_seconds' => 0,
            'requires_answered_disposition' => false,
        ];

        $this->assertTrue($this->evaluator->evaluate($rule, 'NO ANSWER', 0));
    }
}
