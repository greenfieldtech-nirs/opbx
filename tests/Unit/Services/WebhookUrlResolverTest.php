<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Services\WebhookUrlResolver;
use Tests\TestCase;

class WebhookUrlResolverTest extends TestCase
{
    public function test_ring_group_callback_url_matches_the_registered_route(): void
    {
        $organization = new Organization;
        $organization->setRelation('cloudonixSettings', new CloudonixSettings(['webhook_base_url' => 'https://pbx.example.test']));

        $urls = WebhookUrlResolver::getEffectiveWebhookUrls($organization);

        $this->assertSame(
            'https://pbx.example.test/api/callbacks/voice/ring-group-callback',
            $urls['ring_group_callback']
        );
    }
}
