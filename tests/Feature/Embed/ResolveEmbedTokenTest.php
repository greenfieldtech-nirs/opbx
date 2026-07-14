<?php

declare(strict_types=1);

namespace Tests\Feature\Embed;

use App\Http\Middleware\ResolveEmbedToken;
use App\Services\EmbedTokenService;
use Tests\TestCase;

final class ResolveEmbedTokenTest extends TestCase
{
    public function test_origin_matches_allowed_domain(): void
    {
        $mw = new ResolveEmbedToken(app(EmbedTokenService::class));

        $this->assertTrue($mw->originAllowed('https://crm.acme.com', ['crm.acme.com']));
        $this->assertTrue($mw->originAllowed('https://crm.acme.com:8443', ['crm.acme.com']));
        $this->assertFalse($mw->originAllowed('https://evil.com', ['crm.acme.com']));
        $this->assertFalse($mw->originAllowed(null, ['crm.acme.com']));
        $this->assertFalse($mw->originAllowed('', ['crm.acme.com']));
    }
}
