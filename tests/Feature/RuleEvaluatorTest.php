<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\InvalidRuleException;
use Easybdit\LaravelMikrotik\Models\MikrotikAlert;
use Easybdit\LaravelMikrotik\Models\MikrotikRule;
use Easybdit\LaravelMikrotik\Models\MikrotikSnapshot;
use Easybdit\LaravelMikrotik\Monitoring\RuleEvaluator;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RuleEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private function makeSnapshot(string $connection = 'default'): MikrotikSnapshot
    {
        return MikrotikSnapshot::query()->create([
            'connection'  => $connection,
            'captured_at' => now(),
            'resource'    => ['cpu_load' => 95, 'free_memory' => 1024],
            'health'      => ['cpu-temperature' => ['name' => 'cpu-temperature', 'value' => 72, 'type' => 'C']],
            'interfaces'  => ['ether1' => ['name' => 'ether1', 'rx_error' => 12]],
        ]);
    }

    public function test_rule_matching_its_threshold_creates_an_alert(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'High CPU load',
            'metric'     => 'resource.cpu_load',
            'operator'   => '>',
            'threshold'  => 90,
        ]);

        $alerts = (new RuleEvaluator())->evaluate($this->makeSnapshot());

        $this->assertCount(1, $alerts);
        $this->assertDatabaseCount('mikrotik_alerts', 1);
        $this->assertSame('resource.cpu_load', $alerts[0]->metric);
        $this->assertSame(95.0, $alerts[0]->value);
        $this->assertSame(90.0, $alerts[0]->threshold);
        $this->assertSame('High CPU load', $alerts[0]->message);
        $this->assertNotNull($alerts[0]->triggered_at);
    }

    public function test_rule_not_matching_its_threshold_creates_no_alert(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'High CPU load',
            'metric'     => 'resource.cpu_load',
            'operator'   => '>',
            'threshold'  => 99,
        ]);

        $alerts = (new RuleEvaluator())->evaluate($this->makeSnapshot());

        $this->assertCount(0, $alerts);
        $this->assertDatabaseCount('mikrotik_alerts', 0);
    }

    public function test_health_sensor_metric_path_is_resolved(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'CPU too hot',
            'metric'     => 'health.cpu-temperature.value',
            'operator'   => '>=',
            'threshold'  => 70,
        ]);

        $alerts = (new RuleEvaluator())->evaluate($this->makeSnapshot());

        $this->assertCount(1, $alerts);
        $this->assertSame(72.0, $alerts[0]->value);
    }

    public function test_interface_metric_path_is_resolved(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'Interface errors',
            'metric'     => 'interfaces.ether1.rx_error',
            'operator'   => '>',
            'threshold'  => 5,
        ]);

        $alerts = (new RuleEvaluator())->evaluate($this->makeSnapshot());

        $this->assertCount(1, $alerts);
        $this->assertSame(12.0, $alerts[0]->value);
    }

    public function test_a_rule_with_no_connection_applies_to_every_connection(): void
    {
        MikrotikRule::query()->create([
            'connection' => null,
            'name'       => 'Global CPU rule',
            'metric'     => 'resource.cpu_load',
            'operator'   => '>',
            'threshold'  => 90,
        ]);

        $alerts = (new RuleEvaluator())->evaluate($this->makeSnapshot('branch-01'));

        $this->assertCount(1, $alerts);
        $this->assertSame('branch-01', $alerts[0]->connection);
    }

    public function test_a_rule_scoped_to_a_different_connection_is_not_evaluated(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'branch-01',
            'name'       => 'Branch-only rule',
            'metric'     => 'resource.cpu_load',
            'operator'   => '>',
            'threshold'  => 1,
        ]);

        $alerts = (new RuleEvaluator())->evaluate($this->makeSnapshot('default'));

        $this->assertCount(0, $alerts);
    }

    public function test_a_disabled_rule_is_not_evaluated(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'Disabled rule',
            'metric'     => 'resource.cpu_load',
            'operator'   => '>',
            'threshold'  => 1,
            'enabled'    => false,
        ]);

        $alerts = (new RuleEvaluator())->evaluate($this->makeSnapshot());

        $this->assertCount(0, $alerts);
    }

    public function test_a_metric_absent_from_the_snapshot_is_skipped_not_an_error(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'Sensor this device does not have',
            'metric'     => 'health.psu1-voltage.value',
            'operator'   => '<',
            'threshold'  => 5,
        ]);

        $alerts = (new RuleEvaluator())->evaluate($this->makeSnapshot());

        $this->assertCount(0, $alerts);
    }

    public function test_alert_can_be_traced_back_to_its_rule_and_snapshot(): void
    {
        $rule = MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'High CPU load',
            'metric'     => 'resource.cpu_load',
            'operator'   => '>',
            'threshold'  => 90,
        ]);

        $snapshot = $this->makeSnapshot();
        $alert = (new RuleEvaluator())->evaluate($snapshot)[0];

        $this->assertTrue($alert->rule->is($rule));
        $this->assertTrue($alert->snapshot->is($snapshot));
    }

    public function test_saving_a_rule_with_an_unsupported_operator_throws(): void
    {
        $this->expectException(InvalidRuleException::class);

        MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'Bad operator',
            'metric'     => 'resource.cpu_load',
            'operator'   => '~=',
            'threshold'  => 1,
        ]);
    }

    public function test_saving_a_rule_with_an_unsupported_metric_prefix_throws(): void
    {
        $this->expectException(InvalidRuleException::class);

        MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'Bad metric',
            'metric'     => 'topsecret.cpu_load',
            'operator'   => '>',
            'threshold'  => 1,
        ]);
    }

    public function test_alerts_can_be_scoped_to_a_connection(): void
    {
        MikrotikAlert::query()->create([
            'connection'   => 'default',
            'metric'       => 'resource.cpu_load',
            'operator'     => '>',
            'threshold'    => 90,
            'value'        => 95,
            'triggered_at' => now(),
        ]);

        $this->assertSame(1, MikrotikAlert::query()->forConnection('default')->count());
        $this->assertSame(0, MikrotikAlert::query()->forConnection('branch-01')->count());
    }
}
