<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Organization $organization
 */
class CallDetailRecord extends Model
{
    use HasFactory;

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
        'call_start_time',
        'call_end_time',
        'call_answer_time',
        'status',
        'raw_cdr',
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
     * Create a CDR from Cloudonix webhook payload
     *
     * @param array $payload Cloudonix CDR webhook payload
     * @param int $organizationId Organization ID to associate with this CDR
     * @return self
     */
    public static function createFromWebhook(array $payload, int $organizationId): self
    {
        $session = $payload['session'] ?? [];

        return self::create([
            'organization_id' => $organizationId,
            'session_timestamp' => isset($payload['timestamp'])
                ? Carbon::createFromTimestamp($payload['timestamp'])
                : now(),
            'session_token' => $session['token'] ?? null,
            'from' => $payload['from'] ?? 'Unknown',
            'to' => $payload['to'] ?? 'Unknown',
            'disposition' => $payload['disposition'] ?? 'UNKNOWN',
            'duration' => $payload['duration'] ?? 0,
            'billsec' => $payload['billsec'] ?? 0,
            'call_id' => $payload['call_id'] ?? 'unknown',
            'domain' => $payload['domain'] ?? null,
            'subscriber' => $payload['subscriber'] ?? null,
            'cx_trunk_id' => $payload['cx_trunk_id'] ?? null,
            'application' => $payload['application'] ?? null,
            'route' => $payload['route'] ?? null,
            'rated_cost' => $payload['rated_cost'] ?? null,
            'approx_cost' => $payload['approx_cost'] ?? null,
            'sell_cost' => $payload['sell_cost'] ?? null,
            'vapp_server' => $payload['vapp_server'] ?? null,
            'session_id' => $session['id'] ?? null,
            'call_start_time' => isset($session['callStartTime'])
                ? Carbon::createFromTimestampMs($session['callStartTime'])
                : null,
            'call_end_time' => isset($session['callEndTime'])
                ? Carbon::createFromTimestampMs($session['callEndTime'])
                : null,
            'call_answer_time' => isset($session['callAnswerTime'])
                ? Carbon::createFromTimestampMs($session['callAnswerTime'])
                : null,
            'status' => $session['status'] ?? null,
            'raw_cdr' => $payload,
        ]);
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
     * Scope query to a specific organization
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
