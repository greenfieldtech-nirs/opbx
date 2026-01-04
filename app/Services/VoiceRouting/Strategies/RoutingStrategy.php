<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\ExtensionType;
use App\Models\DidNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

interface RoutingStrategy
{
    /**
     * Determine if this strategy can handle the given extension type.
     */
    public function canHandle(ExtensionType $type): bool;

    /**
     * Route the call to the destination.
     *
     * @param Request $request The incoming webhook request
     * @param DidNumber $did The DID number being called
     * @param array $destination The resolved destination resources (extension, ring group, etc.)
     * @return Response The CXML response
     */
    public function route(Request $request, DidNumber $did, array $destination): Response;
}
