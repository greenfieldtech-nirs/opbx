<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OrganizationJoinRequestStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationJoinRequest\StoreRequest;
use App\Models\Organization;
use App\Models\OrganizationJoinRequest;
use App\Models\User;
use App\Models\UserSocialIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OrganizationJoinRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OrganizationJoinRequest::class);

        $requests = OrganizationJoinRequest::where('organization_id', Auth::user()->organization_id)
            ->where('status', OrganizationJoinRequestStatus::PENDING)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($requests);
    }

    public function store(StoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $organization = Organization::where('slug', $validated['organization_slug'])
            ->where('status', 'active')
            ->firstOrFail();

        $joinRequest = OrganizationJoinRequest::create([
            'organization_id' => $organization->id,
            'email' => strtolower($validated['email']),
            'name' => $validated['name'] ?? $validated['email'],
            'provider' => $validated['provider'],
            'provider_subject' => $validated['provider_subject'],
            'status' => OrganizationJoinRequestStatus::PENDING,
            'role' => 'pbx_user',
        ]);

        return response()->json($joinRequest, 201);
    }

    public function approve(OrganizationJoinRequest $joinRequest): JsonResponse
    {
        $this->authorize('approve', $joinRequest);

        if ($joinRequest->status !== OrganizationJoinRequestStatus::PENDING) {
            return response()->json([
                'error' => ['code' => 'JOIN_REQUEST_NOT_PENDING', 'message' => 'Request is not pending.'],
            ], 422);
        }

        $user = User::create([
            'organization_id' => $joinRequest->organization_id,
            'name' => $joinRequest->name,
            'email' => $joinRequest->email,
            'password' => Hash::make(Str::random(32)),
            'role' => UserRole::PBX_USER,
            'status' => UserStatus::ACTIVE,
        ]);

        UserSocialIdentity::create([
            'user_id' => $user->id,
            'provider' => $joinRequest->provider,
            'provider_subject' => $joinRequest->provider_subject,
            'provider_email' => $joinRequest->email,
            'provider_data' => [],
        ]);

        $joinRequest->update(['status' => OrganizationJoinRequestStatus::APPROVED]);

        return response()->json([
            'message' => 'Join request approved.',
            'user' => $user,
        ]);
    }

    public function reject(OrganizationJoinRequest $joinRequest): JsonResponse
    {
        $this->authorize('reject', $joinRequest);

        $joinRequest->update(['status' => OrganizationJoinRequestStatus::REJECTED]);

        return response()->json(['message' => 'Join request rejected.']);
    }
}
