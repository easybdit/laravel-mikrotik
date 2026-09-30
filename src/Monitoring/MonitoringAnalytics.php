<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Monitoring;

use Easybdit\LaravelMikrotik\Models\MikrotikAlert;
use Easybdit\LaravelMikrotik\Models\MikrotikSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only history/trend queries over data P4 (MikrotikSnapshot) and P5
 * (MikrotikAlert) already store. This makes no RouterOS requests of its
 * own -- everything here is mined from rows already persisted by
 * SnapshotRecorder/RuleEvaluator, and every query filters first on
 * `connection` plus the already-indexed `captured_at`/`triggered_at`
 * column (see the P4/P5 migrations) before touching a row's JSON
 * columns, rather than scanning a whole table and filtering in PHP.
 *
 * `resource`/`health`/`interfaces` are stored as JSON text columns (see
 * MikrotikSnapshot) — no database-specific JSON path indexing is relied
 * on here (that would need a schema change this phase deliberately
 * avoids), so a metric/sensor/counter is always extracted in PHP once
 * the relevant rows are already narrowed down by the indexed columns.
 */
final class MonitoringAnalytics
{
    /**
     * Time series of one `resource.*` field (e.g. "cpu_load",
     * "free_memory" — see RouterResource::toArray()'s keys).
     *
     * @return list<array{captured_at: Carbon, value: int|float|string|bool|null}>
     */
    public function resourceTrend(string $connection, string $field, ?Carbon $since = null, ?Carbon $until = null): array
    {
        return $this->pluckTrend($connection, $since, $until, 'resource', $field);
    }

    /**
     * Time series of one health sensor's value (e.g. "cpu-temperature",
     * "voltage" — see HealthReading::toArray()'s keys). A sensor absent
     * from a given snapshot yields `value: null` for that point rather
     * than omitting the point, so gaps in a device's own sensor set stay
     * visible in the series instead of silently compressing the timeline.
     *
     * @return list<array{captured_at: Carbon, value: int|float|string|bool|null}>
     */
    public function healthTrend(string $connection, string $sensor, ?Carbon $since = null, ?Carbon $until = null): array
    {
        return $this->pluckTrend($connection, $since, $until, 'health', "{$sensor}.value");
    }

    /**
     * Time series of one interface counter (e.g. "rx_error", "tx_drop",
     * "rx_byte" — see RouterInterface::toArray()'s keys). Covers both
     * general counter history and the "error/drop tracking" case (pass
     * "rx_error"/"tx_error"/"rx_drop"/"tx_drop" as $counter) with the
     * same query.
     *
     * @return list<array{captured_at: Carbon, value: int|float|string|bool|null}>
     */
    public function interfaceCounterTrend(string $connection, string $interfaceName, string $counter, ?Carbon $since = null, ?Carbon $until = null): array
    {
        return $this->pluckTrend($connection, $since, $until, 'interfaces', "{$interfaceName}.{$counter}");
    }

    /**
     * A per-second rate for one cumulative interface counter, computed
     * between each pair of consecutive stored snapshots -- entirely from
     * already-stored counters (RouterConnection::interfaces(), P1/P2),
     * making no new RouterOS request. Contrast RouterConnection's
     * interfaceRate() (P3): that is RouterOS's own one-shot live reading
     * at the moment of the call; this is this package's own
     * after-the-fact computation from history, over whatever interval
     * snapshots happen to have been recorded at.
     *
     * A pair where the counter decreased (RouterOS resets these counters
     * on interface reset -- typically a reboot -- see the README's
     * "Interfaces and traffic counters" section) is reported with
     * `rate_per_second: null, counter_reset: true` rather than a
     * misleading negative number.
     *
     * @return list<array{from: Carbon, to: Carbon, rate_per_second: float|null, counter_reset: bool}>
     */
    public function interfaceRateHistory(
        string $connection,
        string $interfaceName,
        string $counter = 'rx_byte',
        ?Carbon $since = null,
        ?Carbon $until = null
    ): array {
        $points = $this->snapshotsQuery($connection, $since, $until, ['id', 'captured_at', 'interfaces'])
            ->get()
            ->map(fn (MikrotikSnapshot $s) => [
                'captured_at' => $s->captured_at,
                'value'       => Arr::get($s->interfaces ?? [], "{$interfaceName}.{$counter}"),
            ])
            ->filter(fn (array $p) => is_numeric($p['value']))
            ->values();

        $result = [];

        for ($i = 1; $i < $points->count(); $i++) {
            $previous = $points[$i - 1];
            $current = $points[$i];

            $elapsedSeconds = $current['captured_at']->getTimestamp() - $previous['captured_at']->getTimestamp();
            $delta = $current['value'] - $previous['value'];
            $counterReset = $delta < 0;

            $result[] = [
                'from'            => $previous['captured_at'],
                'to'              => $current['captured_at'],
                'rate_per_second' => (!$counterReset && $elapsedSeconds > 0) ? ((float) $delta) / $elapsedSeconds : null,
                'counter_reset'   => $counterReset,
            ];
        }

        return $result;
    }

    /**
     * MikrotikAlert rows (any status) within the given window, oldest
     * first -- the full model, since alerts are already well-typed and
     * need no further unpacking.
     *
     * @return Collection<int, MikrotikAlert>
     */
    public function alertHistory(string $connection, ?Carbon $since = null, ?Carbon $until = null): Collection
    {
        return $this->alertsQuery($connection, $since, $until)->orderBy('triggered_at')->get();
    }

    /**
     * How many times each rule/metric fired within the given window --
     * a quick "what's noisiest" summary over alert history.
     *
     * @return list<array{rule_id: int|null, metric: string, count: int}>
     */
    public function alertCountsByRuleMetric(string $connection, ?Carbon $since = null, ?Carbon $until = null): array
    {
        return $this->alertsQuery($connection, $since, $until)
            ->selectRaw('rule_id, metric, count(*) as aggregate')
            ->groupBy('rule_id', 'metric')
            ->get()
            ->map(fn ($row) => [
                'rule_id' => $row->rule_id,
                'metric'  => $row->metric,
                'count'   => (int) $row->aggregate,
            ])
            ->all();
    }

    /**
     * @return list<array{captured_at: Carbon, value: mixed}>
     */
    private function pluckTrend(string $connection, ?Carbon $since, ?Carbon $until, string $column, string $path): array
    {
        return $this->snapshotsQuery($connection, $since, $until, ['id', 'captured_at', $column])
            ->get()
            ->map(fn (MikrotikSnapshot $s) => [
                'captured_at' => $s->captured_at,
                'value'       => Arr::get($s->{$column} ?? [], $path),
            ])
            ->all();
    }

    /** @param list<string> $columns @return Builder<MikrotikSnapshot> */
    private function snapshotsQuery(string $connection, ?Carbon $since, ?Carbon $until, array $columns): Builder
    {
        $query = MikrotikSnapshot::query()->select($columns)->forConnection($connection)->orderBy('captured_at');

        if ($since !== null) {
            $query->where('captured_at', '>=', $since);
        }

        if ($until !== null) {
            $query->where('captured_at', '<=', $until);
        }

        return $query;
    }

    /** @return Builder<MikrotikAlert> */
    private function alertsQuery(string $connection, ?Carbon $since, ?Carbon $until): Builder
    {
        $query = MikrotikAlert::query()->forConnection($connection);

        if ($since !== null) {
            $query->where('triggered_at', '>=', $since);
        }

        if ($until !== null) {
            $query->where('triggered_at', '<=', $until);
        }

        return $query;
    }
}
