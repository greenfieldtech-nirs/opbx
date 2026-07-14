<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserEmbedToken;
use App\Services\EmbedTokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class EmbedDialerController extends Controller
{
    public function __construct(private readonly EmbedTokenService $tokens) {}

    /**
     * Serve the iframe document that hosts the embedded dialer widget.
     * Sets a per-token frame-ancestors CSP so only the token's allowed
     * domains may frame it.
     */
    public function dialer(Request $request): Response
    {
        $embedToken = $this->tokens->resolve((string) $request->query('token'));

        if (! $embedToken) {
            return response('<!doctype html><title>Forbidden</title>Invalid embed token.', 403)
                ->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('X-Frame-Options', 'DENY')
                ->header('Content-Security-Policy', "frame-ancestors 'none'");
        }

        $csp = $this->frameAncestors($embedToken);
        $html = $this->renderIframe($request, $embedToken);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Security-Policy', $csp);
    }

    /**
     * Serve the host-side loader IIFE that exposes window.OpbxDialer.
     */
    public function loader(): Response
    {
        $js = (string) file_get_contents(resource_path('embed/loader.js'));

        return response($js, 200)
            ->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=300');
    }

    private function frameAncestors(UserEmbedToken $embedToken): string
    {
        $domains = array_map(
            static fn (string $d): string => 'https://'.$d,
            $embedToken->allowed_domains ?? []
        );

        $ancestors = $domains === [] ? "'none'" : implode(' ', $domains);

        return 'frame-ancestors '.$ancestors;
    }

    private function renderIframe(Request $request, UserEmbedToken $embedToken): string
    {
        $token = (string) $request->query('token');
        $iconPosition = $embedToken->icon_position?->value ?? 'bottom-right';
        $iconColor = $embedToken->icon_background_color ?? '#007acc';
        $widgetUrl = config('embed.widget_url', '/embed/assets/embed-widget.js');

        $config = json_encode([
            'token' => $token,
            'iconPosition' => $iconPosition,
            'iconBackgroundColor' => $iconColor,
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        $widgetUrlAttr = htmlspecialchars($widgetUrl, ENT_QUOTES);

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>OPBX Dialer</title>
  <style>html,body{margin:0;padding:0;background:transparent;height:100%;}</style>
</head>
<body>
  <div id="opbx-dialer-root"></div>
  <script>window.__OPBX_EMBED__ = {$config};</script>
  <script src="{$widgetUrlAttr}"></script>
</body>
</html>
HTML;
    }
}
