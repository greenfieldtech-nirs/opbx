<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OrganizationJoinRequestStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationJoinRequest\StoreRequest;
use App\Models\OrganizationJoinRequest;
use App\Models\User;
use App\Models\UserSocialIdentity;
use App\Scopes\OrganizationScope;
use App\Services\Auth0\Auth0AccountResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

        $key = 'auth0:onboarding:'.$validated['onboarding_id'];
        $profile = Cache::get($key);

        if (! is_array($profile)) {
            return response()->json([
                'error' => ['code' => 'ONBOARDING_EXPIRED', 'message' => 'Onboarding session is invalid or expired.'],
            ], 422);
        }

        $this->ensureEmailIsFree((string) ($profile['email'] ?? ''));

        $joinRequest = app(Auth0AccountResolver::class)->createJoinRequest($validated['organization_slug'], $profile);
        Cache::forget($key); // single use

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

        $this->ensureEmailIsFree($joinRequest->email);

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

    /**
     * Emails are globally unique: a user belongs to exactly one organization.
     */
    private function ensureEmailIsFree(string $email): void
    {
        if (User::withoutGlobalScope(OrganizationScope::class)->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }
    }
}
