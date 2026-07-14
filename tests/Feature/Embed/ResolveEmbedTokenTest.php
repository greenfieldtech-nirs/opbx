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
    }

    public function test_absent_origin_is_allowed(): void
    {
        // The widget calls /embed/config same-origin with the iframe, so the
        // browser omits the Origin header. The bearer token is the real auth;
        // the Origin allowlist only blocks *other* sites, which always send an
        // Origin. So a missing/empty Origin must pass.
        $mw = new ResolveEmbedToken(app(EmbedTokenService::class));

        $this->assertTrue($mw->originAllowed(null, ['crm.acme.com']));
        $this->assertTrue($mw->originAllowed('', ['crm.acme.com']));
    }

    public function test_present_but_unlisted_origin_is_rejected(): void
    {
        // A cross-origin request from a site not on the allowlist is refused.
        $mw = new ResolveEmbedToken(app(EmbedTokenService::class));

        $this->assertFalse($mw->originAllowed('https://evil.com', ['crm.acme.com']));
        $this->assertFalse($mw->originAllowed('https://evil.com', []));
    }
}
