<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\OrganizationScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * Call Detail Record (CDR) Model
 *
 * Stores call detail records received from Cloudonix at the end of each call.
 * CDRs contain information about call duration, disposition, costs, and QoS metrics.
 *
 * @property int $id
 * @property int $organization_id
 * @property Carbon $session_timestamp
 * @property string|null $session_token
 * @property string $from
 * @property string $to
 * @property string|null $extension_number The OPBX extension the call belongs to (see extensionNumberFor()); `from` is the Caller ID
 * @property string|null $direction incoming|outgoing|internal|application, copied from the session update at ingest
 * @property string $disposition
 * @property int $duration Total call duration in seconds
 * @property int $billsec Connected/billable duration in seconds
 * @property string $call_id SIP Call-ID
 * @property string|null $domain
 * @property string|null $subscriber
 * @property int|null $cx_trunk_id
 * @property string|null $application
 * @property string|null $route
 * @property float|null $rated_cost
 * @property float|null $approx_cost
 * @property float|null $sell_cost
 * @property string|null $vapp_server
 * @property int|null $session_id
 * @property Carbon|null $call_start_time
 * @property Carbon|null $call_end_time
 * @property Carbon|null $call_answer_time
 * @property string|null $status
 * @property array $raw_cdr Complete CDR JSON
 * @property string $recording_status none|pending|available|failed
 * @property string|null $recording_source_url The RecordingUrl Cloudonix reported
 * @property string|null $recording_stored_path Object key on the 'recordings' disk
 * @property string|null $recording_mime_type
 * @property int|null $recording_duration Recording length in seconds
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Organization $organization
 */
#[ScopedBy([OrganizationScope::class])]
class CallDetailRecord extends Model
{
    use HasFactory;

    private const ANSWERER_TTL_SECONDS = 86400;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'call_detail_records';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'organization_id',
        'session_timestamp',
        'session_token',
        'from',
        'to',
        'extension_number',
        'disposition',
        'duration',
        'billsec',
        'call_id',
        'domain',
        'subscriber',
        'cx_trunk_id',
        'application',
        'route',
        'rated_cost',
        'approx_cost',
        'sell_cost',
        'vapp_server',
        'session_id',
        'direction',
        'call_start_time',
        'call_end_time',
        'call_answer_time',
        'status',
        'raw_cdr',
        'recording_status',
        'recording_source_url',
        'recording_stored_path',
        'recording_mime_type',
        'recording_duration',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'session_timestamp' => 'datetime',
        'call_start_time' => 'datetime',
        'call_end_time' => 'datetime',
        'call_answer_time' => 'datetime',
        'duration' => 'integer',
        'billsec' => 'integer',
        'cx_trunk_id' => 'integer',
        'session_id' => 'integer',
        'recording_duration' => 'integer',
        'rated_cost' => 'decimal:4',
        'approx_cost' => 'decimal:4',
        'sell_cost' => 'decimal:4',
        'raw_cdr' => 'array',
    ];

    /**
     * Get the organization that owns this CDR.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the extension whose Cloudonix subscriber UUID matches this CDR's
     * `subscriber` field, if any.
     */
    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'subscriber', 'cloudonix_uuid');
    }

    /**
     * Get the most recent session_updates row for this CDR's session, used to
     * surface data (e.g. call direction) that Cloudonix's CDR webhook itself
     * doesn't include but the session-update webhooks do.
     */
    public function sessionUpdate(): HasOne
    {
        return $this->hasOne(SessionUpdate::class, 'session_id', 'session_id')->latestOfMany('id');
    }

    /**
     * Create a CDR from Cloudonix webhook payload
     *
     * @param  array  $payload  Cloudonix CDR webhook payload
     * @param  int  $organizationId  Organization ID to associate with this CDR
     */
    public static function createFromWebhook(array $payload, int $organizationId): self
    {
        $session = $payload['session'] ?? [];
        $profile = $session['profile'] ?? [];
        $amd = $profile['amd'] ?? null;

        // Extract AMD data from CDR payload if present
        $amdResult = $amd['result'] ?? null;
        $amdConfidence = isset($amd['confidence']) ? (float) $amd['confidence'] : null;

        $from = $payload['from'] ?? 'Unknown';
        $subscriber = $payload['subscriber'] ?? null;

        // Calls initiated via the Cloudonix API (Direction: application) have
        // no logged-in subscriber, so Cloudonix's CDR payload carries no
        // `subscriber` UUID and the call would otherwise show as unassigned.
        // If the caller ID matches one of the organization's extension
        // numbers, assert that extension as the call's origin so it's still
        // attributed to its assigned user.
        if (! $subscriber && $from !== 'Unknown') {
            $subscriber = self::assertSubscriberFromCallerId($from, $organizationId);
        }

        $attributes = [
            'organization_id' => $organizationId,
            'session_timestamp' => isset($payload['timestamp'])
                ? Carbon::createFromTimestamp($payload['timestamp'])
                : now(),
            'session_token' => $session['token'] ?? null,
            'from' => $from,
            'to' => $payload['to'] ?? 'Unknown',
            'disposition' => $payload['disposition'] ?? 'UNKNOWN',
            'duration' => $payload['duration'] ?? 0,
            'billsec' => $payload['billsec'] ?? 0,
            'call_id' => $payload['call_id'] ?? 'unknown',
            'domain' => $payload['domain'] ?? null,
            'subscriber' => $subscriber,
            'cx_trunk_id' => $payload['cx_trunk_id'] ?? null,
            'application' => $payload['application'] ?? null,
            'route' => $payload['route'] ?? null,
            'rated_cost' => $payload['rated_cost'] ?? null,
            'approx_cost' => $payload['approx_cost'] ?? null,
            'sell_cost' => $payload['sell_cost'] ?? null,
            'vapp_server' => $payload['vapp_server'] ?? null,
            'session_id' => $session['id'] ?? null,
            'call_start_time' => isset($session['callStartTime']) && $session['callStartTime'] > 0
                ? Carbon::createFromTimestampMs($session['callStartTime'])
                : null,
            'call_end_time' => isset($session['callEndTime']) && $session['callEndTime'] > 0
                ? Carbon::createFromTimestampMs($session['callEndTime'])
                : null,
            'call_answer_time' => isset($session['callAnswerTime']) && $session['callAnswerTime'] > 0
                ? Carbon::createFromTimestampMs($session['callAnswerTime'])
                : null,
            'status' => $session['status'] ?? null,
            'amd_result' => $amdResult,
            'amd_confidence' => $amdConfidence,
            'raw_cdr' => $payload,
        ];
        $attributes['extension_number'] = self::extensionNumberFor($organizationId, $subscriber, $from, $attributes['to'], $attributes['session_token']);

        // No unique DB index on (organization_id, call_id) by owner decision (D9):
        // dedupe here, serialised per call so concurrent deliveries create one row.
        // Callers can tell a replay apart via ->wasRecentlyCreated.
        return Cache::lock("cdr:create:{$organizationId}:{$attributes['call_id']}", 10)->block(5, function () use ($attributes, $organizationId): self {
            return self::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organizationId)
                ->where('call_id', $attributes['call_id'])
                ->first() ?? self::create($attributes + self::directionAttribute($organizationId, $attributes['session_id']));
        });
    }

    /**
     * Direction reported by the session-update webhooks for this CDR's session.
     * The synthetic cdr_final_status row (always 'incoming') is created after the CDR, so it is ignored.
     *
     * @return array<string, string|null>
     */
    private static function directionAttribute(int $organizationId, mixed $sessionId): array
    {
        // shortcut: hasColumn guard lets this code run before the direction migration has been applied; remove after deploy.
        static $hasColumn = null;
        $hasColumn ??= Schema::hasColumn('call_detail_records', 'direction');

        if (! $hasColumn) {
            return [];
        }

        $direction = $sessionId === null ? null : SessionUpdate::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where('session_id', $sessionId)
            ->where(fn ($q) => $q->whereNull('action')->orWhere('action', '!=', 'cdr_final_status'))
            ->orderByDesc('id')
            ->value('direction');

        return ['direction' => $direction];
    }

    /**
     * The organization's extension a call belongs to, independent of the Caller ID it presented:
     * the subscriber that placed it (CDR `subscriber` = extensions.cloudonix_uuid), else an extension
     * number used as the caller ID (API-initiated calls), else the extension its destination DID routes to
     * directly (inbound), else the extension OPBX recorded as answering the call (ring group member, IVR /
     * business-hours target, queue agent; see recordAnsweringExtension()). Null when none applies.
     */
    public static function extensionNumberFor(int $organizationId, ?string $subscriber, string $from, string $to, ?string $sessionToken = null): ?string
    {
        $extensions = fn () => Extension::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $organizationId);

        $number = ($subscriber ? $extensions()->where('cloudonix_uuid', $subscriber)->value('extension_number') : null)
            ?? $extensions()->where('extension_number', ltrim($from, '+'))->value('extension_number');

        if ($number === null && $to !== '') {
            $extensionId = DidNumber::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $organizationId)
                ->whereIn('phone_number', [$to, '+'.ltrim($to, '+')])
                ->first()
                ?->getTargetExtensionId();

            $number = $extensionId ? $extensions()->whereKey($extensionId)->value('extension_number') : null;
        }

        $number ??= $sessionToken ? Redis::get(self::answererKey($organizationId, $sessionToken)) : null;

        return $number === null || $number === false ? null : (string) $number;
    }

    /**
     * Remember the extension that answered (or, for a single-target dial, was handed) an inbound call, keyed by
     * the session token its CDR will carry. First answerer wins. A CDR stored before the answer was known
     * (race) and still unassigned is updated now.
     */
    public static function recordAnsweringExtension(int $organizationId, ?string $sessionToken, ?string $extensionNumber): void
    {
        if (! $sessionToken || ! $extensionNumber) {
            return;
        }

        // Outlives the longest call plus CDR delivery delay; the CDR row is the durable copy.
        if (! Redis::set(self::answererKey($organizationId, $sessionToken), $extensionNumber, 'EX', self::ANSWERER_TTL_SECONDS, 'NX')) {
            return;
        }

        self::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where('session_token', $sessionToken)
            ->whereNull('extension_number')
            ->update(['extension_number' => $extensionNumber]);
    }

    private static function answererKey(int $organizationId, string $sessionToken): string
    {
        return "cdr:answerer:{$organizationId}:{$sessionToken}";
    }

    /**
     * Assert a subscriber UUID for a CDR whose payload didn't include one, by
     * matching the caller ID against an extension number in the organization.
     * Returns null (no assertion) if no matching extension is found.
     */
    private static function assertSubscriberFromCallerId(string $from, int $organizationId): ?string
    {
        return Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where('extension_number', ltrim($from, '+'))
            ->value('cloudonix_uuid');
    }

    /**
     * Get formatted duration (MM:SS)
     */
    public function getFormattedDurationAttribute(): string
    {
        $minutes = floor($this->duration / 60);
        $seconds = $this->duration % 60;

        return sprintf('%02d:%02d', $minutes, $seconds);
    }

    /**
     * Get formatted billable duration (MM:SS)
     */
    public function getFormattedBillsecAttribute(): string
    {
        $minutes = floor($this->billsec / 60);
        $seconds = $this->billsec % 60;

        return sprintf('%02d:%02d', $minutes, $seconds);
    }

    /**
     * Scope a query to only include records for a specific organization.
     * Note: This is in addition to the global OrganizationScope.
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope query to a specific disposition
     */
    public function scopeWithDisposition($query, string $disposition)
    {
        return $query->where('disposition', $disposition);
    }

    /**
     * Get AMD status for display.
     *
     * Returns "Enabled::{result}" if AMD result exists (from raw CDR or
     * fallback to the dedicated amd_result column), or "Disabled" if no
     * AMD data is present.
     */
    public function getAmdStatusAttribute(): string
    {
        // Primary: read from raw CDR payload (Cloudonix-provided)
        $rawCdr = $this->raw_cdr ?? [];
        $session = $rawCdr['session'] ?? [];
        $profile = $session['profile'] ?? [];
        $amd = $profile['amd'] ?? null;

        if ($amd && isset($amd['result'])) {
            return 'Enabled::'.$amd['result'];
        }

        // Fallback: read from dedicated column (populated from local session
        // data when Cloudonix CDR payload is missing AMD profile)
        if ($this->amd_result) {
            return 'Enabled::'.$this->amd_result;
        }

        return 'Disabled';
    }

    /**
     * Scope query to connected calls only
     */
    public function scopeConnected($query)
    {
        return $query->where('disposition', 'CONNECTED');
    }

    /**
     * Scope query to date range
     */
    public function scopeBetweenDates($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('session_timestamp', [$startDate, $endDate]);
    }
}
