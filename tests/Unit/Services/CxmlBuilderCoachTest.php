<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CxmlBuilder\CxmlBuilder;
use Tests\TestCase;

final class CxmlBuilderCoachTest extends TestCase
{
    public function test_spy_emits_listen_policy(): void
    {
        $xml = CxmlBuilder::coach('abc123def4567890', 'listen');

        $this->assertStringContainsString('<Coach', $xml);
        $this->assertStringContainsString('policy="listen"', $xml);
        $this->assertStringContainsString('finishOnKey="#"', $xml);
        $this->assertStringContainsString('>abc123def4567890</Coach>', $xml);
        $this->assertStringNotContainsString('whisperDirection', $xml);
    }

    public function test_barge_emits_barge_policy(): void
    {
        $xml = CxmlBuilder::coach('abc123def4567890', 'barge');

        $this->assertStringContainsString('policy="barge"', $xml);
    }

    public function test_whisper_to_caller(): void
    {
        $xml = CxmlBuilder::coach('abc123def4567890', 'whisper', 'caller');

        $this->assertStringContainsString('policy="whisper"', $xml);
        $this->assertStringContainsString('whisperDirection="caller"', $xml);
    }

    public function test_whisper_to_callee_maps_to_cloudonix_calee_spelling(): void
    {
        $xml = CxmlBuilder::coach('abc123def4567890', 'whisper', 'callee');

        // Cloudonix spells the callee value "calee" (their typo).
        $this->assertStringContainsString('whisperDirection="calee"', $xml);
        $this->assertStringNotContainsString('whisperDirection="callee"', $xml);
    }

    public function test_whisper_to_both(): void
    {
        $xml = CxmlBuilder::coach('abc123def4567890', 'whisper', 'both');

        $this->assertStringContainsString('whisperDirection="both"', $xml);
    }

    public function test_token_is_xml_encoded(): void
    {
        $xml = CxmlBuilder::coach('a&b<c', 'listen');

        $this->assertStringContainsString('a&amp;b&lt;c', $xml);
    }
}
