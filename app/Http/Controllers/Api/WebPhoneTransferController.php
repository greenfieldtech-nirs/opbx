<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ExtensionType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\WebPhoneTransferRequest;
use App\Models\Extension;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\VoiceRouting\CallTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Web Phone blind transfer, the extension directory its transfer field suggests from, and the
 * Cloudonix session token shown on the in-call screen.
 */
final class WebPhoneTransferController extends Controller
{
    use ApiRequestHandler;

    public function transfer(WebPhoneTransferRequest $request, CallTransferService $transfers): JsonResponse
    {
        return self::performTransfer($this->getAuthenticatedUser(), $request->validated(), $transfers);
    }

    public function directory(): JsonResponse
    {
        return response()->json(['data' => self::directoryFor($this->getAuthenticatedUser())]);
    }

    public function session(Request $request, CallTransferService $transfers): JsonResponse
    {
        $input = $request->validate(['call_id' => ['required', 'string', 'max:255']]);

        return self::sessionFor($this->getAuthenticatedUser(), $input['call_id'], $transfers);
    }

    /**
     * The Cloudonix session token of the user's call, proven theirs as for a transfer. 404 until
     * Cloudonix has reported the session (the Web Phone retries).
     */
    public static function sessionFor(User $user, string $callId, CallTransferService $transfers): JsonResponse
    {
        $extension = self::extensionOf($user);
        $session = $extension ? $transfers->findSession($extension, $callId) : null;

        return $session
            ? response()->json(['data' => ['session_token' => (string) $session->session_token]])
            : response()->json(['message' => 'The call was not found.'], 404);
    }

    /**
     * Shared with the embed widget, which authenticates by embed token instead of a session.
     *
     * @param  array{call_id: string, destination: string}  $input
     */
    public static function performTransfer(User $user, array $input, CallTransferService $transfers): JsonResponse
    {
        $extension = self::extensionOf($user);

        if (! $extension) {
            return response()->json(['message' => 'No extension is assigned to this user.'], 404);
        }

        if ($input['destination'] === (string) $extension->extension_number) {
            return response()->json(['message' => 'You cannot transfer a call to your own extension.'], 422);
        }

        $session = $transfers->findSession($extension, $input['call_id']);

        if (! $session) {
            return response()->json(['message' => 'The call was not found. It may have ended.'], 404);
        }

        $transferred = $transfers->blindTransfer($session, $input['call_id'], $extension, $input['destination']);

        Log::info('Web Phone blind transfer requested', [
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'extension_number' => $extension->extension_number,
            'destination' => $input['destination'],
            'transferred' => $transferred,
            'security_event' => true,
        ]);

        return $transferred
            ? response()->json(['data' => ['transferred' => true]])
            : response()->json(['message' => 'The transfer failed. The call is still connected.'], 502);
    }

    /**
     * Active user extensions of the organization other than the user's own, for transfer suggestions.
     *
     * @return array<int, array{extension_number: string, name: string|null}>
     */
    public static function directoryFor(User $user): array
    {
        // Explicit organization filter: the embed widget has no session for the global scope.
        return OrganizationScope::bypass(fn () => Extension::where('organization_id', $user->organization_id)
            ->where('type', ExtensionType::USER)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
            ->with('user:id,name')
            ->orderBy('extension_number')
            ->get()
            ->map(fn (Extension $extension) => [
                'extension_number' => (string) $extension->extension_number,
                'name' => $extension->user?->name,
            ])
            ->all());
    }

    private static function extensionOf(User $user): ?Extension
    {
        return OrganizationScope::bypass(fn () => Extension::where('user_id', $user->id)
            ->where('type', ExtensionType::USER)
            ->where('organization_id', $user->organization_id)
            ->first());
    }
}
