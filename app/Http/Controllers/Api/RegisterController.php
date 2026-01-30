<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class RegisterController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = DB::transaction(function () use ($validated) {
                $organization = Organization::create([
                    'name' => $validated['organization']['name'],
                    'slug' => $validated['organization']['slug'],
                    'status' => 'active',
                    'timezone' => $validated['organization']['timezone'],
                    'settings' => $this->getDefaultSettings(),
                ]);

                $user = User::create([
                    'organization_id' => $organization->id,
                    'name' => $validated['admin']['name'],
                    'email' => $validated['admin']['email'],
                    'password' => Hash::make($validated['admin']['password']),
                    'role' => UserRole::OWNER,
                    'status' => UserStatus::ACTIVE,
                ]);

                $token = $user->createToken(
                    'registration-token',
                    ['*'],
                    now()->addDay()
                )->plainTextToken;

                return [
                    'organization' => $organization,
                    'user' => $user,
                    'token' => $token,
                ];
            });

            Log::info('Organization registered successfully', [
                'organization_id' => $result['organization']->id,
                'organization_name' => $result['organization']->name,
                'user_id' => $result['user']->id,
                'user_email' => $result['user']->email,
            ]);

            return response()->json([
                'message' => 'Organization registered successfully',
                'user' => [
                    'id' => $result['user']->id,
                    'organization_id' => $result['user']->organization_id,
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                    'role' => $result['user']->role->value,
                    'status' => $result['user']->status->value,
                ],
                'organization' => [
                    'id' => $result['organization']->id,
                    'name' => $result['organization']->name,
                    'slug' => $result['organization']->slug,
                    'status' => $result['organization']->status,
                    'timezone' => $result['organization']->timezone,
                ],
                'access_token' => $result['token'],
                'token_type' => 'Bearer',
                'expires_in' => 86400,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Registration failed', [
                'error' => $e->getMessage(),
                'organization_name' => $validated['organization']['name'] ?? null,
                'admin_email' => $validated['admin']['email'] ?? null,
            ]);

            return response()->json([
                'error' => [
                    'code' => 'REGISTRATION_FAILED',
                    'message' => 'An error occurred during registration. Please try again.',
                ],
            ], 500);
        }
    }

    public function validateRegistration(Request $request): JsonResponse
    {
        $response = [];

        if ($request->has('organization_name')) {
            $response['organization_name_available'] = ! Organization::where(
                'name', $request->organization_name
            )->exists();
        }

        if ($request->has('admin_email')) {
            $response['admin_email_available'] = ! User::where(
                'email', $request->admin_email
            )->exists();
        }

        if ($request->has('slug')) {
            $response['slug_available'] = ! Organization::where(
                'slug', $request->slug
            )->exists();
        }

        return response()->json(['data' => $response]);
    }

    private function getDefaultSettings(): array
    {
        return [
            'features' => [
                'call_recording' => false,
                'voicemail' => true,
                'music_on_hold' => true,
                'call_waiting' => true,
            ],
            'ui' => [
                'language' => 'en',
                'date_format' => 'Y-m-d',
                'time_format' => 'H:i',
            ],
            'pbx' => [
                'default_extension_length' => 4,
                'extension_range_start' => '1000',
                'extension_range_end' => '9999',
            ],
        ];
    }
}
