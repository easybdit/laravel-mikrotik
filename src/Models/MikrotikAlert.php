<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One historical record of a MikrotikRule matching a MikrotikSnapshot,
 * written by Monitoring\RuleEvaluator. This is an immutable log entry,
 * not a stateful "active/resolved" alert — this phase (P5) does not
 * track whether a condition is still ongoing or group repeated firings
 * into an incident (see the package README's Known limitations).
 *
 * $connection/$metric/$operator/$threshold/$value are stored directly
 * (not only reachable via $rule/$snapshot) so this row stays meaningful
 * even after its rule or snapshot is later deleted.
 *
 * @property int $id
 * @property int|null $rule_id
 * @property int|null $snapshot_id
 * @property string $connection
 * @property string $metric
 * @property string $operator
 * @property float $threshold
 * @property float $value
 * @property string|null $message
 * @property \Illuminate\Support\Carbon $triggered_at
 */
class MikrotikAlert extends Model
{
    protected $table = 'mikrotik_alerts';

    protected $fillable = [
        'rule_id',
        'snapshot_id',
        'connection',
        'metric',
        'operator',
        'threshold',
        'value',
        'message',
        'triggered_at',
    ];

    protected $casts = [
        'threshold'    => 'float',
        'value'        => 'float',
        'triggered_at' => 'datetime',
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

    /** @param Builder<MikrotikAlert> $query */
    public function scopeForConnection(Builder $query, string $name): Builder
    {
        return $query->where('connection', $name);
    }
}
