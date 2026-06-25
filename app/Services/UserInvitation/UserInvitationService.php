<?php

declare(strict_types=1);

namespace App\Services\UserInvitation;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\Email\Contracts\TransactionalEmailInterface;
use App\Services\Email\DTOs\EmailMessage;
use App\Services\Email\DTOs\EmailRecipient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use RuntimeException;

class UserInvitationService
{
    private const TOKEN_BYTES = 32;

    private const CACHE_PREFIX = 'invite:';

    public function __construct(
        private readonly TransactionalEmailInterface $emailService,
    ) {}

    /**
     * Create a pending user and send an invitation email.
     *
     * @return array{user: User, token: string}
     *
     * @throws RuntimeException if rate limit exceeded
     * @throws InvalidArgumentException if email already exists in organization
     */
    public function invite(User $inviter, string $email): array
    {
        $email = strtolower(trim($email));
        $organizationId = $inviter->organization_id;

        $this->ensureRateLimit($organizationId);

        $existingUser = User::where('organization_id', $organizationId)->where('email', $email)->first();

        if ($existingUser !== null) {
            if ($existingUser->isPending()) {
                $token = $this->createToken($existingUser);
                $this->sendInvitationEmail($existingUser, $token);

                return ['user' => $existingUser, 'token' => $token];
            }

            $this->notifyPlatformManagersOfDuplicateInvite($email, $inviter);

            throw new InvalidArgumentException('A user with this email already exists in the organization.');
        }

        try {
            $user = User::create([
                'organization_id' => $organizationId,
                'name' => $this->placeholderNameFromEmail($email),
                'email' => $email,
                'password' => null,
                'role' => UserRole::PBX_USER,
                'status' => UserStatus::PENDING,
            ]);

            $token = $this->createToken($user);
        } catch (QueryException $e) {
            if ($this->isDuplicateEmailError($e)) {
                throw new InvalidArgumentException('A user with this email already exists.');
            }

            throw $e;
        }

        $this->sendInvitationEmail($user, $token);

        return ['user' => $user, 'token' => $token];
    }

    /**
     * Validate a token without consuming it.
     */
    public function validateToken(string $token): ?User
    {
        $payload = Cache::get(self::CACHE_PREFIX.$this->hashToken($token));

        if ($payload === null) {
            return null;
        }

        $user = OrganizationScope::bypass(fn () => User::find($payload['user_id'] ?? null));

        if ($user === null || $user->organization_id !== (int) $payload['organization_id'] || ! $user->isPending()) {
            return null;
        }

        return $user;
    }

    /**
     * Consume a token and return the pending user.
     */
    public function consumeToken(string $token): ?User
    {
        $payload = Cache::pull(self::CACHE_PREFIX.$this->hashToken($token));

        if ($payload === null) {
            return null;
        }

        $user = OrganizationScope::bypass(fn () => User::find($payload['user_id'] ?? null));

        if ($user === null || $user->organization_id !== (int) $payload['organization_id'] || ! $user->isPending()) {
            return null;
        }

        return $user;
    }

    private function createToken(User $user): string
    {
        $token = $this->generateToken();
        $ttlSeconds = config('services.invitation.token_ttl_hours', 24) * 3600;

        Cache::put(
            self::CACHE_PREFIX.$this->hashToken($token),
            ['user_id' => $user->id, 'organization_id' => $user->organization_id, 'email' => $user->email],
            $ttlSeconds
        );

        return $token;
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function placeholderNameFromEmail(string $email): string
    {
        return explode('@', $email)[0];
    }

    private function isDuplicateEmailError(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'users.email') || str_contains($message, 'users_email');
    }

    private function ensureRateLimit(int $organizationId): void
    {
        $maxAttempts = config('services.invitation.rate_limit_per_hour', 10);
        $key = 'invite:org:'.$organizationId;

        $allowed = RateLimiter::attempt($key, $maxAttempts, fn () => true, 3600);

        if (! $allowed) {
            throw new RuntimeException('Invitation rate limit exceeded for this organization.');
        }
    }

    private function sendInvitationEmail(User $user, string $token): void
    {
        $organization = $user->organization;
        $frontendUrl = rtrim(config('services.invitation.frontend_url', config('app.url', 'http://localhost')), '/');
        $link = $frontendUrl.'/ui/invite?token='.urlencode($token);
        $ttlHours = config('services.invitation.token_ttl_hours', 24);

        $message = new EmailMessage(
            from: new EmailRecipient(config('mail.from.address', 'noreply@opbx.local'), config('mail.from.name', 'OPBX')),
            to: [new EmailRecipient($user->email)],
            subject: "You've been invited to join {$organization->name} on OPBX",
            htmlContent: view('emails.user-invitation', [
                'organizationName' => $organization->name,
                'inviteLink' => $link,
                'ttlHours' => $ttlHours,
            ])->render(),
            textContent: view('emails.user-invitation-text', [
                'organizationName' => $organization->name,
                'inviteLink' => $link,
                'ttlHours' => $ttlHours,
            ])->render(),
        );

        $this->emailService->sendAsync($message);
    }

    private function notifyPlatformManagersOfDuplicateInvite(string $email, User $inviter): void
    {
        $managers = OrganizationScope::bypass(fn () => User::where('is_platform_manager', true)->get());

        if ($managers->isEmpty()) {
            return;
        }

        $frontendUrl = rtrim(config('services.invitation.frontend_url', config('app.url', 'http://localhost')), '/');

        $message = new EmailMessage(
            from: new EmailRecipient(config('mail.from.address', 'noreply@opbx.local'), config('mail.from.name', 'OPBX')),
            to: $managers->map(fn (User $u) => new EmailRecipient($u->email))->all(),
            subject: 'Duplicate invitation attempt alert',
            htmlContent: view('emails.duplicate-invite-alert', [
                'email' => $email,
                'inviterName' => $inviter->name,
                'inviterEmail' => $inviter->email,
                'organizationName' => $inviter->organization->name,
                'usersUrl' => $frontendUrl.'/ui/users',
            ])->render(),
            textContent: "An invitation was attempted for {$email} in {$inviter->organization->name} by {$inviter->name} ({$inviter->email}), but a user with that email already exists.",
        );

        $this->emailService->sendAsync($message);
    }
}
