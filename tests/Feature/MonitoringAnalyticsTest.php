<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Models\MikrotikAlert;
use Easybdit\LaravelMikrotik\Models\MikrotikIncident;
use Easybdit\LaravelMikrotik\Models\MikrotikRule;
use Easybdit\LaravelMikrotik\Models\MikrotikSnapshot;
use Easybdit\LaravelMikrotik\Monitoring\IncidentManager;
use Easybdit\LaravelMikrotik\Monitoring\MonitoringAnalytics;
use Easybdit\LaravelMikrotik\Monitoring\RuleEvaluator;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

class MonitoringAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSnapshot(string $connection, Carbon $capturedAt, int $cpuLoad, int $rxByte, ?int $cpuTemp = null): MikrotikSnapshot
    {
        return MikrotikSnapshot::query()->create([
            'connection'  => $connection,
            'captured_at' => $capturedAt,
            'resource'    => ['cpu_load' => $cpuLoad, 'free_memory' => 1024],
            'health'      => $cpuTemp !== null
                ? ['cpu-temperature' => ['name' => 'cpu-temperature', 'value' => $cpuTemp, 'type' => 'C']]
                : [],
            'interfaces'  => ['ether1' => ['name' => 'ether1', 'rx_byte' => $rxByte, 'rx_error' => 0, 'tx_error' => 0]],
        ]);
    }

    public function test_resource_trend_returns_an_ordered_time_series(): void
    {
        $t1 = Carbon::parse('2026-01-01 00:00:00');
        $t2 = Carbon::parse('2026-01-01 00:05:00');
        $this->makeSnapshot('default', $t2, cpuLoad: 50, rxByte: 200); // inserted out of order on purpose
        $this->makeSnapshot('default', $t1, cpuLoad: 10, rxByte: 100);

        $trend = (new MonitoringAnalytics())->resourceTrend('default', 'cpu_load');

        $this->assertCount(2, $trend);
        $this->assertTrue($trend[0]['captured_at']->equalTo($t1));
        $this->assertSame(10, $trend[0]['value']);
        $this->assertTrue($trend[1]['captured_at']->equalTo($t2));
        $this->assertSame(50, $trend[1]['value']);
    }

    public function test_resource_trend_is_scoped_to_its_connection(): void
    {
        $t = Carbon::parse('2026-01-01 00:00:00');
        $this->makeSnapshot('default', $t, cpuLoad: 10, rxByte: 100);
        $this->makeSnapshot('branch-01', $t, cpuLoad: 90, rxByte: 100);

        $trend = (new MonitoringAnalytics())->resourceTrend('default', 'cpu_load');

        $this->assertCount(1, $trend);
        $this->assertSame(10, $trend[0]['value']);
    }

    public function test_resource_trend_respects_since_and_until(): void
    {
        $t1 = Carbon::parse('2026-01-01 00:00:00');
        $t2 = Carbon::parse('2026-01-02 00:00:00');
        $t3 = Carbon::parse('2026-01-03 00:00:00');
        $this->makeSnapshot('default', $t1, cpuLoad: 1, rxByte: 1);
        $this->makeSnapshot('default', $t2, cpuLoad: 2, rxByte: 2);
        $this->makeSnapshot('default', $t3, cpuLoad: 3, rxByte: 3);

        $trend = (new MonitoringAnalytics())->resourceTrend('default', 'cpu_load', since: $t2, until: $t2);

        $this->assertCount(1, $trend);
        $this->assertSame(2, $trend[0]['value']);
    }

    public function test_health_trend_reports_null_when_a_snapshot_lacks_the_sensor(): void
    {
        $t1 = Carbon::parse('2026-01-01 00:00:00');
        $t2 = Carbon::parse('2026-01-01 00:05:00');
        $this->makeSnapshot('default', $t1, cpuLoad: 1, rxByte: 1, cpuTemp: 45);
        $this->makeSnapshot('default', $t2, cpuLoad: 1, rxByte: 1, cpuTemp: null);

        $trend = (new MonitoringAnalytics())->healthTrend('default', 'cpu-temperature');

        $this->assertSame(45, $trend[0]['value']);
        $this->assertNull($trend[1]['value']);
    }

    public function test_interface_counter_trend_covers_error_and_drop_tracking(): void
    {
        $t = Carbon::parse('2026-01-01 00:00:00');
        MikrotikSnapshot::query()->create([
            'connection' => 'default', 'captured_at' => $t,
            'resource' => [], 'health' => [],
            'interfaces' => ['ether1' => ['rx_error' => 7, 'tx_drop' => 3]],
        ]);

        $analytics = new MonitoringAnalytics();
        $this->assertSame(7, $analytics->interfaceCounterTrend('default', 'ether1', 'rx_error')[0]['value']);
        $this->assertSame(3, $analytics->interfaceCounterTrend('default', 'ether1', 'tx_drop')[0]['value']);
    }

    public function test_interface_rate_history_computes_per_second_rate_between_snapshots(): void
    {
        $t1 = Carbon::parse('2026-01-01 00:00:00');
        $t2 = Carbon::parse('2026-01-01 00:00:10'); // +10s
        $this->makeSnapshot('default', $t1, cpuLoad: 1, rxByte: 1000);
        $this->makeSnapshot('default', $t2, cpuLoad: 1, rxByte: 2000); // +1000 bytes / 10s

        $history = (new MonitoringAnalytics())->interfaceRateHistory('default', 'ether1', 'rx_byte');

        $this->assertCount(1, $history);
        $this->assertSame(100.0, $history[0]['rate_per_second']);
        $this->assertFalse($history[0]['counter_reset']);
    }

    public function test_interface_rate_history_flags_a_counter_reset_instead_of_a_negative_rate(): void
    {
        $t1 = Carbon::parse('2026-01-01 00:00:00');
        $t2 = Carbon::parse('2026-01-01 00:00:10');
        $this->makeSnapshot('default', $t1, cpuLoad: 1, rxByte: 5000);
        $this->makeSnapshot('default', $t2, cpuLoad: 1, rxByte: 100); // reboot -- counter reset

        $history = (new MonitoringAnalytics())->interfaceRateHistory('default', 'ether1', 'rx_byte');

        $this->assertCount(1, $history);
        $this->assertNull($history[0]['rate_per_second']);
        $this->assertTrue($history[0]['counter_reset']);
    }

    public function test_interface_rate_history_skips_snapshots_missing_the_counter(): void
    {
        $t1 = Carbon::parse('2026-01-01 00:00:00');
        $t2 = Carbon::parse('2026-01-01 00:00:10');
        $t3 = Carbon::parse('2026-01-01 00:00:20');
        MikrotikSnapshot::query()->create(['connection' => 'default', 'captured_at' => $t1, 'resource' => [], 'health' => [], 'interfaces' => ['ether1' => ['rx_byte' => 100]]]);
        MikrotikSnapshot::query()->create(['connection' => 'default', 'captured_at' => $t2, 'resource' => [], 'health' => [], 'interfaces' => []]); // gap: sensor absent
        MikrotikSnapshot::query()->create(['connection' => 'default', 'captured_at' => $t3, 'resource' => [], 'health' => [], 'interfaces' => ['ether1' => ['rx_byte' => 300]]]);

        $history = (new MonitoringAnalytics())->interfaceRateHistory('default', 'ether1', 'rx_byte');

        // Only the t1 -> t3 pair is computable; the gap is not treated as 0.
        $this->assertCount(1, $history);
        $this->assertTrue($history[0]['from']->equalTo($t1));
        $this->assertTrue($history[0]['to']->equalTo($t3));
        $this->assertSame(10.0, $history[0]['rate_per_second']); // (300-100)/20s
    }

    public function test_alert_history_returns_full_models_ordered_by_triggered_at(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'High CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 90,
        ]);

        $snapshot = $this->makeSnapshot('default', Carbon::parse('2026-01-01'), cpuLoad: 95, rxByte: 1);
        (new RuleEvaluator())->evaluate($snapshot);

        $history = (new MonitoringAnalytics())->alertHistory('default');

        $this->assertCount(1, $history);
        $this->assertInstanceOf(MikrotikAlert::class, $history->first());
        $this->assertSame('resource.cpu_load', $history->first()->metric);
    }

    public function test_alert_counts_by_rule_metric_summarizes_frequency(): void
    {
        $rule = MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'High CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 90,
        ]);

        $evaluator = new RuleEvaluator();
        // trigger, resolve, trigger again -- 2 historical rows for this rule.
        $evaluator->evaluate($this->makeSnapshot('default', Carbon::parse('2026-01-01 00:00:00'), 95, 1));
        $evaluator->evaluate($this->makeSnapshot('default', Carbon::parse('2026-01-01 00:05:00'), 10, 1));
        $evaluator->evaluate($this->makeSnapshot('default', Carbon::parse('2026-01-01 00:10:00'), 95, 1));

        $counts = (new MonitoringAnalytics())->alertCountsByRuleMetric('default');

        $this->assertCount(1, $counts);
        $this->assertSame($rule->id, $counts[0]['rule_id']);
        $this->assertSame('resource.cpu_load', $counts[0]['metric']);
        $this->assertSame(2, $counts[0]['count']);
    }

    /** @return array{triggered: list<MikrotikAlert>, justResolved: \Illuminate\Database\Eloquent\Collection<int, MikrotikAlert>} */
    private function evaluateAndDiff(RuleEvaluator $evaluator, MikrotikSnapshot $snapshot): array
    {
        $activeIdsBefore = MikrotikAlert::query()->forConnection($snapshot->connection)->active()->pluck('id');
        $triggered = $evaluator->evaluate($snapshot);
        $justResolved = MikrotikAlert::query()
            ->whereIn('id', $activeIdsBefore->diff(
                MikrotikAlert::query()->forConnection($snapshot->connection)->active()->pluck('id')
            ))
            ->get();

        return ['triggered' => $triggered, 'justResolved' => $justResolved];
    }

    public function test_open_incidents_returns_only_currently_open_ones_for_the_connection(): void
    {
        $rule = MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'High CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 90,
        ]);

        $evaluator = new RuleEvaluator();
        $incidents = new IncidentManager();

        $r = $this->evaluateAndDiff($evaluator, $this->makeSnapshot('default', Carbon::parse('2026-01-01 00:00:00'), 95, 1));
        $incidents->reconcile($r['triggered'], $r['justResolved']);

        $open = (new MonitoringAnalytics())->openIncidents('default');

        $this->assertCount(1, $open);
        $this->assertSame($rule->id, $open->first()->rule_id);
        $this->assertSame(MikrotikIncident::STATUS_OPEN, $open->first()->status);
    }

    public function test_incident_history_and_counts_by_rule_and_average_duration(): void
    {
        $rule = MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'High CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 90,
        ]);

        $evaluator = new RuleEvaluator();
        $incidentManager = new IncidentManager();

        // RuleEvaluator stamps triggered_at/resolved_at with the real
        // wall-clock time (correct production behavior -- it does not
        // trust a caller-supplied snapshot timestamp for that), so
        // opened_at/resolved_at are set explicitly here afterward to get
        // deterministic, well-separated durations for this test.
        $r1 = $this->evaluateAndDiff($evaluator, $this->makeSnapshot('default', Carbon::parse('2026-01-01 00:00:00'), 95, 1));
        $incidentManager->reconcile($r1['triggered'], $r1['justResolved']);
        $r2 = $this->evaluateAndDiff($evaluator, $this->makeSnapshot('default', Carbon::parse('2026-01-01 00:10:00'), 10, 1));
        $incidentManager->reconcile($r2['triggered'], $r2['justResolved']);
        // Incident 1: open 00:00, resolve 00:10 -- 600s duration.
        MikrotikIncident::query()->where('id', $r1['triggered'][0]->fresh()->incident_id)->update([
            'opened_at' => Carbon::parse('2026-01-01 00:00:00'),
            'resolved_at' => Carbon::parse('2026-01-01 00:10:00'),
        ]);

        $r3 = $this->evaluateAndDiff($evaluator, $this->makeSnapshot('default', Carbon::parse('2026-01-01 00:20:00'), 95, 1));
        $incidentManager->reconcile($r3['triggered'], $r3['justResolved']);
        $r4 = $this->evaluateAndDiff($evaluator, $this->makeSnapshot('default', Carbon::parse('2026-01-01 00:40:00'), 10, 1));
        $incidentManager->reconcile($r4['triggered'], $r4['justResolved']);
        // Incident 2: open 00:20, resolve 00:40 -- 1200s duration.
        MikrotikIncident::query()->where('id', $r3['triggered'][0]->fresh()->incident_id)->update([
            'opened_at' => Carbon::parse('2026-01-01 00:20:00'),
            'resolved_at' => Carbon::parse('2026-01-01 00:40:00'),
        ]);

        $analytics = new MonitoringAnalytics();

        $history = $analytics->incidentHistory('default');
        $this->assertCount(2, $history);
        $this->assertTrue($history[0]->opened_at->lessThan($history[1]->opened_at));

        $counts = $analytics->incidentCountsByRule('default');
        $this->assertCount(1, $counts);
        $this->assertSame($rule->id, $counts[0]['rule_id']);
        $this->assertSame(2, $counts[0]['count']);

        // (600 + 1200) / 2 = 900 seconds.
        $this->assertSame(900.0, $analytics->averageIncidentDurationSeconds('default'));
    }

    public function test_average_incident_duration_is_null_when_none_are_resolved_yet(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'High CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 90,
        ]);

        $evaluator = new RuleEvaluator();
        $incidentManager = new IncidentManager();
        $r = $this->evaluateAndDiff($evaluator, $this->makeSnapshot('default', Carbon::parse('2026-01-01'), 95, 1));
        $incidentManager->reconcile($r['triggered'], $r['justResolved']);

        $this->assertNull((new MonitoringAnalytics())->averageIncidentDurationSeconds('default'));
    }

    public function test_incident_analytics_are_isolated_per_connection(): void
    {
        MikrotikRule::query()->create([
            'connection' => null, 'name' => 'Global high CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 90,
        ]);

        $evaluator = new RuleEvaluator();
        $incidentManager = new IncidentManager();

        $rDefault = $this->evaluateAndDiff($evaluator, $this->makeSnapshot('default', Carbon::parse('2026-01-01'), 95, 1));
        $incidentManager->reconcile($rDefault['triggered'], $rDefault['justResolved']);

        $rBranch = $this->evaluateAndDiff($evaluator, $this->makeSnapshot('branch-01', Carbon::parse('2026-01-01'), 95, 1));
        $incidentManager->reconcile($rBranch['triggered'], $rBranch['justResolved']);

        $analytics = new MonitoringAnalytics();
        $this->assertCount(1, $analytics->openIncidents('default'));
        $this->assertCount(1, $analytics->openIncidents('branch-01'));
        $this->assertCount(1, $analytics->incidentHistory('default'));
        $this->assertCount(1, $analytics->incidentHistory('branch-01'));
    }
}
