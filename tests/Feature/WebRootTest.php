<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The legacy Laravel welcome page (and its root Vite build) is gone: nginx serves the site root
 * from the frontend, and a direct hit on the PHP app is sent to the frontend app.
 */
final class WebRootTest extends TestCase
{
    public function test_root_redirects_to_the_frontend_app(): void
    {
        $this->get('/')->assertRedirect('/ui');
    }
}
