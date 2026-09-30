<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record of a MikrotikRule matching a MikrotikSnapshot, written by
 * Monitoring\RuleEvaluator. Carries a simple two-state lifecycle:
 * 'triggered' while the condition is still considered ongoing, and
 * 'resolved' once a later evaluation of the same rule no longer matches
 * ($resolved_at recording when). RuleEvaluator never deletes or rewrites
 * a row's identity to do this — it only ever inserts a new row or flips
 * an existing row's $status/$resolved_at, so history is always
 * preserved. $incident_id (P10) links this row to the MikrotikIncident
 * (Monitoring\IncidentManager) that groups this rule's alert episode —
 * see MikrotikIncident's own docblock for exactly what "grouping" means
 * in this phase.
 *
 * $connection/$metric/$operator/$threshold/$value are stored directly
 * (not only reachable via $rule/$snapshot) so this row stays meaningful
 * even after its rule or snapshot is later deleted.
 *
 * @property int $id
 * @property int|null $rule_id
 * @property int|null $snapshot_id
 * @property int|null $incident_id
 * @property string $connection
 * @property string $metric
 * @property string $operator
 * @property float $threshold
 * @property float $value
 * @property string $status
 * @property string|null $message
 * @property \Illuminate\Support\Carbon $triggered_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 */
class MikrotikAlert extends Model
{
    protected $table = 'mikrotik_alerts';

    public const STATUS_TRIGGERED = 'triggered';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'rule_id',
        'snapshot_id',
        'incident_id',
        'connection',
        'metric',
        'operator',
        'threshold',
        'value',
        'status',
        'message',
        'triggered_at',
        'resolved_at',
    ];

    protected $casts = [
        'threshold'    => 'float',
        'value'        => 'float',
        'triggered_at' => 'datetime',
        'resolved_at'  => 'datetime',
    ];

    /** @return BelongsTo<MikrotikRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(MikrotikRule::class, 'rule_id');
    }

    /** @return BelongsTo<MikrotikSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(MikrotikSnapshot::class, 'snapshot_id');
    }

    /**
     * The MikrotikIncident (P10) this alert belongs to, if any. Null for
     * an alert recorded before P10, or one whose rule_id was null when
     * evaluated (see Monitoring\IncidentManager).
     *
     * @return BelongsTo<MikrotikIncident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(MikrotikIncident::class, 'incident_id');
    }

    /** @param Builder<MikrotikAlert> $query */
    public function scopeForConnection(Builder $query, string $name): Builder
    {
        return $query->where('connection', $name);
    }

    /** Alerts whose condition is still considered ongoing. @param Builder<MikrotikAlert> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_TRIGGERED);
    }

    /** @param Builder<MikrotikAlert> $query */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }
}
