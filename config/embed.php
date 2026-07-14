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

];
