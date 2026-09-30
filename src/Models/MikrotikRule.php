<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Models;

use Easybdit\LaravelMikrotik\Exceptions\InvalidRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A threshold rule evaluated against a MikrotikSnapshot by
 * Monitoring\RuleEvaluator, e.g. "alert when resource.cpu_load > 90" or
 * "alert when health.cpu-temperature.value >= 70".
 *
 * $connection null means the rule applies to every connection; set it to
 * a specific named connection (config('mikrotik.connections.<name>')) to
 * scope it. $metric is a dot path into a snapshot's stored `resource`,
 * `health`, or `interfaces` array — see the metric path examples above
 * and RuleEvaluator's docblock for exactly how it is resolved.
 *
 * Both $operator and the "resource."/"health."/"interfaces." prefix on
 * $metric are validated when the rule is saved (see booted() below), so
 * a malformed rule fails immediately rather than being silently ignored
 * the next time a snapshot is evaluated against it.
 *
 * @property int $id
 * @property string|null $connection
 * @property string $name
 * @property string $metric
 * @property string $operator
 * @property float $threshold
 * @property bool $enabled
 */
class MikrotikRule extends Model
{
    protected $table = 'mikrotik_rules';

    /** Operators RuleEvaluator knows how to apply. Float equality ("==", "!=") is exact — be aware of floating-point precision. */
    public const OPERATORS = ['>', '>=', '<', '<=', '==', '!='];

    /** Snapshot sections a $metric path is allowed to start with. */
    public const METRIC_PREFIXES = ['resource.', 'health.', 'interfaces.'];

    protected $fillable = [
        'connection',
        'name',
        'metric',
        'operator',
        'threshold',
        'enabled',
    ];

    protected $casts = [
        'threshold' => 'float',
        'enabled'   => 'bool',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $rule): void {
            if (!in_array($rule->operator, self::OPERATORS, true)) {
                throw InvalidRuleException::unsupportedOperator((string) $rule->operator);
            }

            $metric = (string) $rule->metric;
            $hasKnownPrefix = false;
            foreach (self::METRIC_PREFIXES as $prefix) {
                if (str_starts_with($metric, $prefix)) {
                    $hasKnownPrefix = true;
                    break;
                }
            }

            if (!$hasKnownPrefix) {
                throw InvalidRuleException::unsupportedMetric($metric);
            }
        });
    }

    /** @param Builder<MikrotikRule> $query */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Rules that apply to $name: either scoped to it directly, or with
     * no connection set at all (applies to every connection).
     *
     * @param Builder<MikrotikRule> $query
     */
    public function scopeForConnection(Builder $query, string $name): Builder
    {
        return $query->where(function (Builder $q) use ($name) {
            $q->whereNull('connection')->orWhere('connection', $name);
        });
    }
}
