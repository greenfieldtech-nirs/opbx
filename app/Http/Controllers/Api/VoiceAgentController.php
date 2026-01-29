<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VoiceAgent;
use App\Http\Resources\VoiceAgentResource;
use App\Http\Resources\VoiceAgentCollection;
use App\Http\Requests\StoreVoiceAgentRequest;
use App\Http\Requests\UpdateVoiceAgentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class VoiceAgentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): VoiceAgentCollection
    {
        Gate::authorize('viewAny', VoiceAgent::class);

        $query = VoiceAgent::where('tenant_id', auth()->user()->tenant_id);

        // Apply filters
        if ($request->has('search') && !empty($request->search)) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->has('enabled')) {
            $query->where('enabled', $request->boolean('enabled'));
        }

        if ($request->has('provider')) {
            $query->where('provider', $request->provider);
        }

        // Apply sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');

        if (in_array($sortBy, ['name', 'provider', 'enabled', 'created_at', 'updated_at'])) {
            $query->orderBy($sortBy, $sortDirection === 'asc' ? 'asc' : 'desc');
        }

        $agents = $query->paginate($request->get('per_page', 20));

        return new VoiceAgentCollection($agents);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreVoiceAgentRequest $request): JsonResponse
    {
        Gate::authorize('create', VoiceAgent::class);

        try {
            $validated = $request->validated();

            // Get the current tenant
            $tenantId = auth()->user()->tenant_id;

            $agent = VoiceAgent::create([
                'tenant_id' => $tenantId,
                ...$validated
            ]);

            Log::info('Voice agent created', [
                'agent_id' => $agent->id,
                'name' => $agent->name,
                'provider' => $agent->provider->value,
                'tenant_id' => $tenantId,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Voice agent created successfully',
                'data' => new VoiceAgentResource($agent)
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create voice agent', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Failed to create voice agent',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(VoiceAgent $voiceAgent): VoiceAgentResource
    {
        Gate::authorize('view', $voiceAgent);

        return new VoiceAgentResource($voiceAgent);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateVoiceAgentRequest $request, VoiceAgent $voiceAgent): JsonResponse
    {
        Gate::authorize('update', $voiceAgent);

        try {
            $validated = $request->validated();

            $voiceAgent->update($validated);

            Log::info('Voice agent updated', [
                'agent_id' => $voiceAgent->id,
                'changes' => array_keys($validated),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Voice agent updated successfully',
                'data' => new VoiceAgentResource($voiceAgent)
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update voice agent', [
                'agent_id' => $voiceAgent->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Failed to update voice agent',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle the enabled status of the voice agent.
     */
    public function toggleStatus(VoiceAgent $voiceAgent): JsonResponse
    {
        Gate::authorize('toggle', $voiceAgent);

        try {
            $voiceAgent->update([
                'enabled' => !$voiceAgent->enabled
            ]);

            $status = $voiceAgent->enabled ? 'enabled' : 'disabled';

            Log::info('Voice agent status toggled', [
                'agent_id' => $voiceAgent->id,
                'status' => $status,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => "Voice agent {$status} successfully",
                'data' => new VoiceAgentResource($voiceAgent)
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to toggle voice agent status', [
                'agent_id' => $voiceAgent->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Failed to toggle voice agent status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(VoiceAgent $voiceAgent): JsonResponse
    {
        Gate::authorize('delete', $voiceAgent);

        try {
            $agentName = $voiceAgent->name;
            $agentId = $voiceAgent->id;

            // Check if agent is used in any groups
            // TODO: Re-enable when agent groups are implemented in WP3
            // if ($voiceAgent->groups()->count() > 0) {
            //     return response()->json([
            //         'message' => 'Cannot delete voice agent that is assigned to agent groups',
            //         'groups_count' => $voiceAgent->groups()->count()
            //     ], 422);
            // }

            $voiceAgent->delete();

            Log::info('Voice agent deleted', [
                'agent_id' => $agentId,
                'name' => $agentName,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Voice agent deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete voice agent', [
                'agent_id' => $voiceAgent->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Failed to delete voice agent',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get validation errors for a voice agent configuration.
     */
    public function validateConfig(VoiceAgent $voiceAgent): JsonResponse
    {
        Gate::authorize('validateConfig', $voiceAgent);

        $errors = $voiceAgent->getValidationErrors();

        if (empty($errors)) {
            return response()->json([
                'valid' => true,
                'message' => 'Voice agent configuration is valid'
            ]);
        }

        return response()->json([
            'valid' => false,
            'errors' => $errors
        ], 422);
    }

    /**
     * Get supported providers information.
     */
    public function providers(): JsonResponse
    {
        Gate::authorize('viewProviders', VoiceAgent::class);

        $providers = [];

        foreach (\App\Enums\VoiceAgentProvider::cases() as $provider) {
            $providers[$provider->value] = [
                'name' => $provider->getDisplayName(),
                'requires_auth' => $provider->requiresAuthentication(),
                'username_label' => $provider->getUsernameLabel(),
                'password_label' => $provider->getPasswordLabel(),
                'service_value_description' => $provider->getServiceValueDescription(),
                'validation_rules' => $provider->getValidationRules(),
            ];
        }

        return response()->json([
            'providers' => $providers
        ]);
    }
}
