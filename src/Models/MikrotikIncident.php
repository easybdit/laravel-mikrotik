<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Groups one rule's continuous triggered->resolved alert episode (P5)
 * into a longer-lived record, written by Monitoring\IncidentManager. In
 * this phase, an incident maps one-to-one onto exactly one
 * MikrotikAlert's lifetime — P5's own deduplication already guarantees
 * at most one active ('triggered') alert per rule at a time, so there is
 * nothing to group across *multiple* alert rows yet; this deliberately
 * does not attempt cross-rule correlation or "flapping" grouping (see
 * the package README's Known limitations).
 *
 * This still earns its own table rather than just querying alerts
 * directly, because it gives "this problem is ongoing" a stable
 * identity independent of any one measurement's value/threshold, which
 * is what incident-level analytics (Monitoring\MonitoringAnalytics) and
 * any future cross-episode grouping need — without touching
 * MikrotikAlert's existing columns.
 *
 * $open_rule_id mirrors $rule_id only while $status is 'open', and is
 * cleared to null on resolution — the unique index on
 * ($open_rule_id, $connection) is what makes "at most one open incident
 * per rule+connection" a real database-level guarantee, safe even under
 * two concurrent `mikrotik:monitor` processes (see IncidentManager).
 *
 * @property int $id
 * @property int|null $rule_id
 * @property int|null $first_alert_id
 * @property string $connection
 * @property string $metric
 * @property string $status
 * @property int|null $open_rule_id
 * @property \Illuminate\Support\Carbon $opened_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 */
class MikrotikIncident extends Model
{
    protected $table = 'mikrotik_incidents';

    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'rule_id',
        'first_alert_id',
        'connection',
        'metric',
        'status',
        'open_rule_id',
        'opened_at',
        'resolved_at',
    ];

    protected $casts = [
        'opened_at'   => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<MikrotikRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(MikrotikRule::class, 'rule_id');
    }

    /** The alert that opened this incident. Its own $status/$resolved_at reflect this incident's current state (P5 updates it in place, never a new row, for the whole episode). @return BelongsTo<MikrotikAlert, $this> */
    public function firstAlert(): BelongsTo
    {
        return $this->belongsTo(MikrotikAlert::class, 'first_alert_id');
    }

    /** Every alert linked to this incident (in this phase: always exactly the one returned by firstAlert()). @return HasMany<MikrotikAlert> */
    public function alerts(): HasMany
    {
        return $this->hasMany(MikrotikAlert::class, 'incident_id');
    }

    /** @param Builder<MikrotikIncident> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /** @param Builder<MikrotikIncident> $query */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }

    /** @param Builder<MikrotikIncident> $query */
    public function scopeForConnection(Builder $query, string $name): Builder
    {
        return $query->where('connection', $name);
    }
}
