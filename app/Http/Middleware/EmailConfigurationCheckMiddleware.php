<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Email Configuration Check Middleware
 *
 * Injects email configuration errors into the view for display.
 */
class EmailConfigurationCheckMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check for email configuration error
        if ($error = Config::get('services.transactional_email.error')) {
            // Share with all views
            view()->share('emailConfigError', $error);
            view()->share('emailEnabledProviders', Config::get('services.transactional_email.enabled_providers', []));
        }

        return $next($request);
    }
}
