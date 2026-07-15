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

    public function test_dev_origins_with_localhost_ip_and_port_match(): void
    {
        // Local dev origins carry a port. A host:port allowlist entry matches
        // the corresponding origin; a portless entry still matches any port.
        $mw = new ResolveEmbedToken(app(EmbedTokenService::class));

        $this->assertTrue($mw->originAllowed('http://localhost:3000', ['localhost:3000']));
        $this->assertTrue($mw->originAllowed('http://localhost:3000', ['localhost']));
        $this->assertTrue($mw->originAllowed('http://127.0.0.1:3000', ['127.0.0.1:3000']));
        $this->assertTrue($mw->originAllowed('https://192.168.2.240', ['192.168.2.240']));
    }

    public function test_dev_origin_with_wrong_port_is_rejected(): void
    {
        // When the allowlist entry pins a port, a different port is refused.
        $mw = new ResolveEmbedToken(app(EmbedTokenService::class));

        $this->assertFalse($mw->originAllowed('http://localhost:4000', ['localhost:3000']));
    }
}
