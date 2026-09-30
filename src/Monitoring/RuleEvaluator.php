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
 * connection, and maintains each rule's alert lifecycle:
 *
 *   - Rule matches, no active ('triggered') alert exists for it yet ->
 *     a new MikrotikAlert is created with status 'triggered'.
 *   - Rule matches, an active alert for it already exists -> nothing
 *     happens (deduplication: repeated matching evaluations do not pile
 *     up duplicate rows for the same ongoing condition).
 *   - Rule no longer matches, but an active alert for it exists -> that
 *     alert is flipped to 'resolved' (with $resolved_at set). It is
 *     never deleted or rewritten beyond that.
 *   - Rule matches again after its previous alert was resolved -> since
 *     there is no longer an active alert for that rule, this is treated
 *     the same as the first case: a new, separate historical alert row
 *     is created.
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
 * silently skipped -- not an error, and not treated as "no longer
 * matches" either: an existing active alert is left untouched rather
 * than resolved on the strength of missing data. A non-numeric value is
 * skipped the same way. A disabled rule is not evaluated at all.
 *
 * This class does not run automatically -- nothing in P1-P4 calls it,
 * and it is not wired into a scheduler yet (see the README's Known
 * limitations; that is P6's job). It also does not group a rule's
 * triggered/resolved cycles into an incident -- each is an independent
 * historical row.
 */
final class RuleEvaluator
{
    /**
     * @return list<MikrotikAlert> Newly created ('triggered') alerts only,
     *   in rule order. A rule whose active alert is resolved during this
     *   call, or that dedupes against an already-active alert, produces
     *   no entry here (the change is still persisted, just not returned).
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
            $isMatch = $this->matches($value, $rule->operator, $rule->threshold);

            // Scoped by connection, not just rule_id: a rule with
            // connection=null applies to every connection (see
            // MikrotikRule's docblock), and each one tracks its own
            // independent active-alert state -- without this, a global
            // rule already active for one connection would incorrectly
            // be seen as "already active" for a second, unrelated
            // connection too, since MikrotikAlert (not MikrotikRule)
            // is what actually carries the connection per episode.
            $activeAlert = MikrotikAlert::query()
                ->where('rule_id', $rule->id)
                ->where('connection', $snapshot->connection)
                ->active()
                ->first();

            if ($isMatch) {
                if ($activeAlert !== null) {
                    continue; // already active -- deduplicated, not a new row
                }

                $alerts[] = MikrotikAlert::query()->create([
                    'rule_id'      => $rule->id,
                    'snapshot_id'  => $snapshot->id,
                    'connection'   => $snapshot->connection,
                    'metric'       => $rule->metric,
                    'operator'     => $rule->operator,
                    'threshold'    => $rule->threshold,
                    'value'        => $value,
                    'status'       => MikrotikAlert::STATUS_TRIGGERED,
                    'message'      => $rule->name,
                    'triggered_at' => Carbon::now(),
                ]);

                continue;
            }

            if ($activeAlert !== null) {
                $activeAlert->update([
                    'status'      => MikrotikAlert::STATUS_RESOLVED,
                    'resolved_at' => Carbon::now(),
                ]);
            }
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
