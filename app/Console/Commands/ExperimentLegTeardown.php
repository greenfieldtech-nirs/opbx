<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CloudonixSettings;
use App\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DIAGNOSTIC EXPERIMENT (workplan WP2-T1: queue-ringall-conference-offers).
 *
 * Determines whether losing agent legs of a parallel CXML <Dial> (the
 * SIP Call-IDs listed in a session's profile.callIds) can be hung up
 * individually via DELETE /calls/{domain}/sessions/{id}.
 *
 * Default mode is read-only: it fetches the session, classifies each
 * callId (caller leg vs agent legs) and prints what WOULD be deleted.
 * Pass --execute to actually attempt the DELETEs. The caller leg is
 * never deleted, and nothing is deleted unless the session status is live.
 *
 * Usage:
 *   php artisan opbx:experiment-leg-teardown {sessionToken} [--org=2] [--execute]
 */
class ExperimentLegTeardown extends Command
{
    /**
     * Session statuses that indicate the call is still live. Anything else
     * blocks --execute entirely (terminal/unknown = hands off).
     */
    private const LIVE_STATUSES = [
        'connected',
        'answered',
        'active',
        'in-progress',
        'ringing',
        'progress',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'opbx:experiment-leg-teardown
                          {sessionToken : Cloudonix session token of the caller session}
                          {--org=2 : Organization ID whose Cloudonix settings to use}
                          {--execute : Actually DELETE the agent-leg IDs (default: dry-run)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'WP2-T1 diagnostic: probe whether parallel Dial agent legs are individually deletable via the Cloudonix sessions API';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $orgId = (int) $this->option('org');
        $sessionToken = (string) $this->argument('sessionToken');
        $execute = (bool) $this->option('execute');

        $settings = OrganizationScope::bypass(
            fn () => CloudonixSettings::where('organization_id', $orgId)->first()
        );

        if (! $settings || ! $settings->domain_uuid || ! $settings->domain_api_key) {
            $this->error("No Cloudonix settings (domain_uuid + domain_api_key) for organization {$orgId}.");

            return self::FAILURE;
        }

        $baseUrl = rtrim(config('cloudonix.api.base_url', 'https://api.cloudonix.io'), '/');
        $domain = $settings->domain_uuid;
        $authHeader = 'Bearer '.$settings->domain_api_key;

        $this->info("WP2-T1 leg-teardown experiment (org {$orgId}, domain {$settings->domain_name})");
        $this->line('Mode: '.($execute ? 'EXECUTE (will DELETE agent legs)' : 'DRY-RUN (read-only)'));
        $this->newLine();

        // 1. Fetch the session
        $response = Http::withHeaders([
            'Authorization' => $authHeader,
            'Accept' => 'application/json',
        ])->timeout(30)->get("{$baseUrl}/calls/{$domain}/sessions/{$sessionToken}");

        if ($response->status() === 404) {
            $this->warn("Session {$sessionToken} not found (404). It may have already ended and been purged.");

            return self::SUCCESS;
        }

        if (! $response->successful()) {
            $this->error("GET session failed: HTTP {$response->status()}");
            $this->line($response->body());

            return self::FAILURE;
        }

        $session = $response->json();
        $status = (string) ($session['status'] ?? 'unknown');
        $profile = $session['profile'] ?? [];
        $callIds = array_values(array_filter((array) ($profile['callIds'] ?? [])));
        $callerLegId = $profile['callId'] ?? $callIds[0] ?? null;

        $this->line("Session token : {$sessionToken}");
        $this->line("Status        : {$status}");
        $this->line('profile.callId: '.($callerLegId ?? '(none)'));
        $this->newLine();

        if (empty($callIds)) {
            $this->warn('Session has no profile.callIds — nothing to classify.');

            return self::SUCCESS;
        }

        // 2. Classify legs: caller leg = profile.callId (or first callId);
        //    agent legs of a parallel <Dial> carry an '@host:port' suffix.
        $agentLegs = [];

        $this->info('Leg classification:');

        foreach ($callIds as $id) {
            $isCaller = ($id === $callerLegId);
            $isAgentLeg = str_contains($id, '@');
            $kind = $isCaller ? 'CALLER LEG (never deleted)' : ($isAgentLeg ? 'agent leg' : 'unknown (no @ suffix — treating as agent leg candidate)');
            $this->line("  [{$kind}] {$id}");

            if (! $isCaller) {
                $agentLegs[] = $id;
            }
        }

        $this->newLine();

        if (empty($agentLegs)) {
            $this->info('No agent legs found. Nothing to do.');

            return self::SUCCESS;
        }

        // 3. Dry-run report / execute guard
        if (! $execute) {
            $this->info('DRY-RUN: would attempt DELETE /calls/'.$domain.'/sessions/{id} for:');

            foreach ($agentLegs as $id) {
                $this->line("  - {$id}");
            }

            $this->newLine();
            $this->line('Re-run with --execute during a live ring-all test call to perform the experiment.');

            return self::SUCCESS;
        }

        $statusLower = strtolower($status);

        if (! in_array($statusLower, self::LIVE_STATUSES, true)) {
            $this->error("Refusing to delete: session status '{$status}' is not live. Aborting without any DELETE.");
            Log::warning('ExperimentLegTeardown: blocked, session not live', [
                'session_token' => $sessionToken,
                'status' => $status,
            ]);

            return self::FAILURE;
        }

        // 4. Execute: DELETE each agent leg, verbatim form first, then the
        //    part before '@' if the verbatim form 404s. One try/catch per leg.
        //    Interactive confirm per leg: the harness cannot distinguish the
        //    WINNER leg from losers (both carry @host:port suffixes) — the
        //    human running the experiment must skip the winner, or the live
        //    bridge may be dropped.
        foreach ($agentLegs as $legId) {
            if (! $this->confirm("DELETE leg [{$legId}]? (say NO for the winner/answered leg)", false)) {
                $this->line("  {$legId}: skipped by operator");

                continue;
            }

            try {
                $this->deleteLeg($baseUrl, $domain, $authHeader, $legId, $sessionToken);
            } catch (\Throwable $e) {
                $this->error("  {$legId}: EXCEPTION {$e->getMessage()}");
                Log::error('ExperimentLegTeardown: exception deleting leg', [
                    'session_token' => $sessionToken,
                    'leg_id' => $legId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function deleteLeg(string $baseUrl, string $domain, string $authHeader, string $legId, string $sessionToken): void
    {
        $candidates = str_contains($legId, '@')
            ? [$legId, explode('@', $legId)[0]]  // verbatim, then stripped of @host:port
            : [$legId];

        foreach ($candidates as $candidate) {
            $response = Http::withHeaders([
                'Authorization' => $authHeader,
                'Accept' => 'application/json',
            ])->timeout(30)->delete("{$baseUrl}/calls/{$domain}/sessions/".rawurlencode($candidate));

            $this->line("  DELETE {$candidate} -> HTTP {$response->status()}");
            Log::info('ExperimentLegTeardown: leg delete attempt', [
                'session_token' => $sessionToken,
                'leg_id' => $legId,
                'attempted_id' => $candidate,
                'http_status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            if ($response->successful()) {
                $this->info("  {$candidate}: DELETED (leg-level teardown works with this ID form)");

                return;
            }

            // 404 on the verbatim form -> fall through to the stripped form.
            if ($response->status() !== 404) {
                $this->warn("  {$candidate}: HTTP {$response->status()} — not retrying alternate forms");
                $this->line('  body: '.mb_substr($response->body(), 0, 300));

                return;
            }
        }

        $this->warn("  {$legId}: 404 on all ID forms — leg-level teardown NOT viable via this endpoint");
    }
}
