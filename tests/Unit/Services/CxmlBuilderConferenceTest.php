<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CxmlBuilder\CxmlBuilder;
use Tests\TestCase;

final class CxmlBuilderConferenceTest extends TestCase
{
    public function test_wait_url_ampersand_is_escaped_exactly_once(): void
    {
        $xml = (new CxmlBuilder)->dialConference('room1', waitUrl: 'https://x.example/moh?a=1&b=2')->build();

        $this->assertStringContainsString('waitUrl="https://x.example/moh?a=1&amp;b=2"', $xml);
        $this->assertStringNotContainsString('&amp;amp;', $xml);
    }
}
