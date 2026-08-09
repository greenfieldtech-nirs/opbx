<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Embedded Dialer Widget URL
    |--------------------------------------------------------------------------
    |
    | URL of the standalone embed widget bundle loaded inside the dialer
    | iframe. Defaults to the app-served bundle under public/embed/.
    |
    */

    'widget_url' => env('EMBED_WIDGET_URL', '/embed/assets/embed-widget.js'),

    /*
    |--------------------------------------------------------------------------
    | Embedded Dialer Widget Stylesheet URL
    |--------------------------------------------------------------------------
    |
    | The widget bundle is built with cssCodeSplit disabled, so its styles are
    | emitted as a separate file the iframe must load alongside the script.
    |
    */

    'widget_css_url' => env('EMBED_WIDGET_CSS_URL', '/embed/assets/embed-widget.css'),

    /*
    |--------------------------------------------------------------------------
    | Allow Insecure (http) Framing
    |--------------------------------------------------------------------------
    |
    | The iframe's frame-ancestors CSP normally forces the https:// scheme so
    | the dialer can only be embedded on secure origins. For LOCAL DEVELOPMENT
    | against http://localhost demo pages, enable this to also emit http://
    | ancestors. NEVER enable this in production — it lets the dialer (and the
    | SIP credentials it loads) be framed by insecure origins.
    |
    */

    'allow_insecure_framing' => (bool) env('EMBED_ALLOW_INSECURE_FRAMING', false),

];
