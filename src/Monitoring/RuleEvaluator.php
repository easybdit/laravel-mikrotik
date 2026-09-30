<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Monitoring;

use Easybdit\LaravelMikrotik\Models\MikrotikAlert;
use Easybdit\LaravelMikrotik\Models\MikrotikRule;
use Easybdit\LaravelMikrotik\Models\MikrotikSnapshot;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Evaluates every enabled MikrotikRule that applies to a MikrotikSnapshot's
 * connection, and records a MikrotikAlert for each one that matches.
 *
 * A rule's $metric is a dot path into the snapshot: the first segment
 * selects which stored array to read ("resource", "health", or
 * "interfaces" -- the same shape RouterResource::toArray(),
 * HealthReading::toArray(), and InterfaceCollection::toArray() already
 * produce, see MikrotikSnapshot), and the remainder is resolved into
 * that array with Illuminate\Support\Arr::get() dot-notation, e.g.:
 *
 *   "resource.cpu_load"              -> $snapshot->resource['cpu_load']
 *   "health.cpu-temperature.value"   -> $snapshot->health['cpu-temperature']['value']
 *   "interfaces.ether1.rx_error"     -> $snapshot->interfaces['ether1']['rx_error']
 *
 * A metric that is absent from this particular snapshot (e.g. a sensor
 * this device doesn't expose, or an interface that wasn't present) is
 * silently skipped, not an error -- consistent with how resource()/
 * health()/interfaces() themselves never throw for absent device data.
 * A non-numeric value is likewise skipped: only numeric comparisons are
 * supported.
 *
 * This class does not run automatically -- nothing in P1-P4 calls it,
 * and it is not wired into a scheduler yet (see the README's Known
 * limitations; that is P6's job).
 */
final class RuleEvaluator
{
    /**
     * @return list<MikrotikAlert> One row per rule that matched, in rule order.
     */
    public function evaluate(MikrotikSnapshot $snapshot): array
    {
        $alerts = [];

        foreach ($this->rulesFor($snapshot->connection) as $rule) {
            $value = $this->extractMetric($snapshot, $rule->metric);

            if ($value === null || !is_numeric($value)) {
                continue;
            }

            $value = (float) $value;

            if (!$this->matches($value, $rule->operator, $rule->threshold)) {
                continue;
            }

            $alerts[] = MikrotikAlert::query()->create([
                'rule_id'      => $rule->id,
                'snapshot_id'  => $snapshot->id,
                'connection'   => $snapshot->connection,
                'metric'       => $rule->metric,
                'operator'     => $rule->operator,
                'threshold'    => $rule->threshold,
                'value'        => $value,
                'message'      => $rule->name,
                'triggered_at' => Carbon::now(),
            ]);
        }

        return $alerts;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, MikrotikRule> */
    private function rulesFor(string $connectionName): \Illuminate\Database\Eloquent\Collection
    {
        return MikrotikRule::query()->enabled()->forConnection($connectionName)->get();
    }

    private function extractMetric(MikrotikSnapshot $snapshot, string $metric): mixed
    {
        [$section, $path] = array_pad(explode('.', $metric, 2), 2, null);

        $data = match ($section) {
            'resource'   => $snapshot->resource,
            'health'     => $snapshot->health,
            'interfaces' => $snapshot->interfaces,
            default      => null,
        };

        if (!is_array($data) || $path === null) {
            return null;
        }

        return Arr::get($data, $path);
    }

    private function matches(float $value, string $operator, float $threshold): bool
    {
        return match ($operator) {
            '>'  => $value > $threshold,
            '>=' => $value >= $threshold,
            '<'  => $value < $threshold,
            '<=' => $value <= $threshold,
            '==' => $value === $threshold,
            '!=' => $value !== $threshold,
            // Unreachable in normal use -- MikrotikRule::booted() rejects
            // an unrecognised operator at save time. Kept as a safety net
            // rather than assuming that validation can never be bypassed.
            default => false,
        };
    }
}
