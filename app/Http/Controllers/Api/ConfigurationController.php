<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApplicationConfig;
use Illuminate\Http\JsonResponse;

/**
 * Application Configuration Controller
 *
 * Provides application-level configuration to the frontend.
 */
class ConfigurationController extends Controller
{
    /**
     * Get application configuration
     *
     * Returns configuration details for SaaS mode and URL overrides.
     */
    public function index(): JsonResponse
    {
        return response()->json(
            ApplicationConfig::getConfigurationSummary()
        );
    }
}
