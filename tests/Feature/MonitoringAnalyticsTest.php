<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Models\MikrotikAlert;
use Easybdit\LaravelMikrotik\Models\MikrotikRule;
use Easybdit\LaravelMikrotik\Models\MikrotikSnapshot;
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
}
