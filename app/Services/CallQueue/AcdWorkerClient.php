<?php

declare(strict_types=1);

namespace App\Services\CallQueue;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the acd-worker (Java/Vert.x) call queue engine.
 *
 * The worker owns ephemeral queue state (FIFO, agent states) in Redis.
 * All methods fail soft: a unreachable worker never crashes the voice path —
 * callers keep hearing hold music and the worker recovers state on poll-miss.
 */
class AcdWorkerClient
{
    private const TIMEOUT_SECONDS = 2;

    public function enqueue(int $organizationId, int $queueId, string $callId): ?int
    {
        $response = $this->post('/queue/enqueue', [
            'orgId' => (string) $organizationId,
            'queueId' => (string) $queueId,
            'callId' => $callId,
        ]);

        if ($response === null) {
            return null;
        }

        return $response->json('position');
    }

    /**
     * @param  array<int, array{userId: int, extensionNumber: string}>  $roster
     * @return array{action: string, position?: int, agents?: array<int, array{userId: string, extensionNumber: string}>}|null
     */
    public function poll(int $organizationId, int $queueId, string $callId, array $roster, string $strategy, int $maxWaitSeconds): ?array
    {
        $response = $this->post('/queue/poll', [
            'orgId' => (string) $organizationId,
            'queueId' => (string) $queueId,
            'callId' => $callId,
            'agents' => array_map(fn (array $agent) => [
                'userId' => (string) $agent['userId'],
                'extensionNumber' => $agent['extensionNumber'],
            ], $roster),
            'strategy' => $strategy,
            'maxWaitSeconds' => $maxWaitSeconds,
        ]);

        return $response?->json();
    }

    public function event(int $organizationId, int $queueId, string $callId, string $type, ?int $agentUserId = null, ?int $talkSeconds = null, ?int $wrapUpSeconds = null): void
    {
        $payload = array_filter([
            'orgId' => (string) $organizationId,
            'queueId' => (string) $queueId,
            'callId' => $callId,
            'type' => $type,
            'agentUserId' => $agentUserId !== null ? (string) $agentUserId : null,
            'talkSeconds' => $talkSeconds,
            'wrapUpSeconds' => $wrapUpSeconds,
        ], fn ($value) => $value !== null);

        $this->post('/events', $payload);
    }

    /**
     * @param  array<int, array{userId: int, extensionNumber: string}>  $roster
     */
    public function live(int $organizationId, int $queueId, array $roster): ?array
    {
        $response = $this->post('/queue/live', [
            'orgId' => (string) $organizationId,
            'queueId' => (string) $queueId,
            'agents' => array_map(fn (array $agent) => [
                'userId' => (string) $agent['userId'],
                'extensionNumber' => $agent['extensionNumber'],
            ], $roster),
        ]);

        return $response?->json();
    }

    /**
     * @param  array{talkSecondsTotal?: int, callsHandledTotal?: int, wrapUpSeconds?: int}  $extra
     * @return bool whether the worker accepted the state change
     */
    public function setAgentState(int $organizationId, int $queueId, int $userId, string $state, array $extra = []): bool
    {
        $payload = array_filter(array_merge([
            'orgId' => (string) $organizationId,
            'queueId' => (string) $queueId,
            'userId' => (string) $userId,
            'state' => $state,
        ], $extra), fn ($value) => $value !== null);

        return $this->post('/agents/state', $payload) !== null;
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.acd_worker.url'), '/'))
            ->withToken((string) config('services.acd_worker.api_token'))
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(1)
            ->retry(0);
    }

    /**
     * Fire-and-forget POST wrapper: logs and returns null on connection errors
     * or non-2xx responses (e.g. worker auth failures must never be silent).
     */
    private function post(string $path, array $payload): ?\Illuminate\Http\Client\Response
    {
        try {
            $response = $this->request()->post($path, $payload);

            if ($response->failed()) {
                Log::warning('ACD worker rejected request', [
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => \Illuminate\Support\Str::limit($response->body(), 300),
                ]);

                return null;
            }

            return $response;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('ACD worker unreachable', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
