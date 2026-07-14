<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\EmbedToken\UpdateEmbedTokenRequest;
use App\Http\Resources\EmbedTokenResource;
use App\Models\User;
use App\Models\UserEmbedToken;
use App\Scopes\OrganizationScope;
use App\Services\EmbedTokenService;
use App\Services\WebhookUrlResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserEmbedTokenController extends Controller
{
    use ApiRequestHandler;

    public function __construct(private readonly EmbedTokenService $tokens) {}

    public function show(Request $request, int $user): JsonResponse
    {
        $target = $this->authorizeAndResolve($request, $user);

        return response()->json([
            'data' => new EmbedTokenResource($this->tokenFor($target)),
        ]);
    }

    public function update(UpdateEmbedTokenRequest $request, int $user): JsonResponse
    {
        $target = $this->authorizeAndResolve($request, $user);
        $model = $this->tokenFor($target);

        $validated = $request->validated();

        OrganizationScope::bypass(function () use ($model, $validated): void {
            $model->fill(array_filter([
                'icon_position' => $validated['icon_position'] ?? null,
                'icon_background_color' => $validated['icon_background_color'] ?? null,
            ], static fn ($v) => $v !== null));

            $model->save();
        });

        return response()->json(['data' => new EmbedTokenResource($model)]);
    }

    public function regenerate(Request $request, int $user): JsonResponse
    {
        $target = $this->authorizeAndResolve($request, $user);

        [$model, $plaintext] = $this->tokens->regenerateFor($target);

        return response()->json([
            'data' => new EmbedTokenResource($model),
            'token' => $plaintext,
            'snippet' => $this->buildSnippet($model, $plaintext, $target),
        ]);
    }

    /**
     * Resolve the target user (ignoring org scope so cross-org access yields a
     * deliberate 403 rather than a 404) and enforce actor role + same-org.
     */
    private function authorizeAndResolve(Request $request, int $userId): User
    {
        $actor = $this->getAuthenticatedUser();

        $target = OrganizationScope::bypass(fn () => User::find($userId));

        if (! $target) {
            abort(404);
        }

        if (! $actor->role->canManageUsers() || $actor->organization_id !== $target->organization_id) {
            abort(403);
        }

        return $target;
    }

    private function tokenFor(User $target): UserEmbedToken
    {
        $model = OrganizationScope::bypass(
            fn () => UserEmbedToken::where('user_id', $target->id)->first()
        );

        if (! $model) {
            [$model] = $this->tokens->generateFor($target);
        }

        return $model;
    }

    private function buildSnippet(UserEmbedToken $model, string $plaintext, User $target): string
    {
        // Use the organization's public webhook base URL — the same address
        // Cloudonix reaches OpBX on — so the snippet points at a routable host
        // rather than the internal request host (e.g. "nginx" inside Docker).
        $organization = OrganizationScope::bypass(fn () => $target->organization);
        $baseUrl = $organization
            ? WebhookUrlResolver::resolveWebhookBaseUrl($organization)
            : null;

        // Fall back to APP_URL (same fallback the webhook URL getters use) rather
        // than the request host, which is internal inside Docker.
        $baseUrl = rtrim($baseUrl ?? (string) config('app.url'), '/');
        $loaderUrl = "{$baseUrl}/embed/loader.js";
        $iconPosition = $model->icon_position?->value ?? 'bottom-right';
        $iconColor = $model->icon_background_color ?? '#007acc';

        return <<<HTML
<script>
  (function(w,d,s,c){var j=d.createElement(s);j.async=1;j.src=c.loaderUrl;
  j.onload=function(){w.OpbxDialer.init(c);};d.head.appendChild(j);})
  (window,document,'script',{
    loaderUrl:'{$loaderUrl}',
    token:'{$plaintext}',
    iconPosition:'{$iconPosition}',
    iconBackgroundColor:'{$iconColor}'
  });
</script>
HTML;
    }
}
