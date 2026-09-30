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
        $this->assertSame(MikrotikAlert::STATUS_TRIGGERED, $alerts[0]->status);
        $this->assertNull($alerts[0]->resolved_at);
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

    public function test_repeated_matching_evaluation_does_not_duplicate_the_active_alert(): void
    {
        $rule = MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'High CPU load',
            'metric'     => 'resource.cpu_load',
            'operator'   => '>',
            'threshold'  => 90,
        ]);

        $evaluator = new RuleEvaluator();

        $first = $evaluator->evaluate($this->makeSnapshot());
        $this->assertCount(1, $first);
        $this->assertDatabaseCount('mikrotik_alerts', 1);

        // Same condition, a later snapshot -- evaluated again.
        $second = $evaluator->evaluate($this->makeSnapshot());
        $this->assertCount(0, $second, 'a still-active alert must not be duplicated');
        $this->assertDatabaseCount('mikrotik_alerts', 1);

        $alert = MikrotikAlert::query()->where('rule_id', $rule->id)->sole();
        $this->assertSame(MikrotikAlert::STATUS_TRIGGERED, $alert->status);
        $this->assertNull($alert->resolved_at);
    }

    public function test_condition_no_longer_matching_resolves_the_active_alert(): void
    {
        $rule = MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'High CPU load',
            'metric'     => 'resource.cpu_load',
            'operator'   => '>',
            'threshold'  => 90,
        ]);

        $evaluator = new RuleEvaluator();

        $evaluator->evaluate($this->makeSnapshot()); // cpu_load 95 -- triggers

        $normalSnapshot = MikrotikSnapshot::query()->create([
            'connection'  => 'default',
            'captured_at' => now(),
            'resource'    => ['cpu_load' => 10],
            'health'      => [],
            'interfaces'  => [],
        ]);

        $alerts = $evaluator->evaluate($normalSnapshot);

        $this->assertCount(0, $alerts, 'resolving an alert must not itself be reported as a new one');
        $this->assertDatabaseCount('mikrotik_alerts', 1);

        $alert = MikrotikAlert::query()->where('rule_id', $rule->id)->sole();
        $this->assertSame(MikrotikAlert::STATUS_RESOLVED, $alert->status);
        $this->assertNotNull($alert->resolved_at);
    }

    public function test_resolved_alert_matching_again_creates_a_new_historical_alert(): void
    {
        $rule = MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'High CPU load',
            'metric'     => 'resource.cpu_load',
            'operator'   => '>',
            'threshold'  => 90,
        ]);

        $evaluator = new RuleEvaluator();

        // Trigger, then resolve.
        $evaluator->evaluate($this->makeSnapshot());
        $evaluator->evaluate(MikrotikSnapshot::query()->create([
            'connection' => 'default', 'captured_at' => now(),
            'resource' => ['cpu_load' => 10], 'health' => [], 'interfaces' => [],
        ]));
        $this->assertDatabaseCount('mikrotik_alerts', 1);

        // Trigger again -- must be a new, separate row, not a revived one.
        $thirdAlerts = $evaluator->evaluate($this->makeSnapshot());

        $this->assertCount(1, $thirdAlerts);
        $this->assertDatabaseCount('mikrotik_alerts', 2);

        $all = MikrotikAlert::query()->where('rule_id', $rule->id)->orderBy('id')->get();
        $this->assertSame(MikrotikAlert::STATUS_RESOLVED, $all[0]->status);
        $this->assertNotNull($all[0]->resolved_at);
        $this->assertSame(MikrotikAlert::STATUS_TRIGGERED, $all[1]->status);
        $this->assertNull($all[1]->resolved_at);
        $this->assertSame(1, MikrotikAlert::query()->where('rule_id', $rule->id)->active()->count());
        $this->assertSame(1, MikrotikAlert::query()->where('rule_id', $rule->id)->resolved()->count());
    }

    public function test_missing_metric_leaves_an_existing_active_alert_untouched(): void
    {
        $rule = MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'CPU too hot',
            'metric'     => 'health.cpu-temperature.value',
            'operator'   => '>=',
            'threshold'  => 70,
        ]);

        $evaluator = new RuleEvaluator();
        $evaluator->evaluate($this->makeSnapshot()); // health.cpu-temperature.value = 72 -- triggers

        // A later snapshot where this device's health payload doesn't
        // include the sensor at all (e.g. a transient/partial read).
        $sparseSnapshot = MikrotikSnapshot::query()->create([
            'connection' => 'default', 'captured_at' => now(),
            'resource' => ['cpu_load' => 1], 'health' => [], 'interfaces' => [],
        ]);

        $alerts = $evaluator->evaluate($sparseSnapshot);

        $this->assertCount(0, $alerts);
        $alert = MikrotikAlert::query()->where('rule_id', $rule->id)->sole();
        $this->assertSame(MikrotikAlert::STATUS_TRIGGERED, $alert->status, 'missing metric must not resolve an active alert');
        $this->assertNull($alert->resolved_at);
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
