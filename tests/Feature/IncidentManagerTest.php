<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Models\MikrotikAlert;
use Easybdit\LaravelMikrotik\Models\MikrotikIncident;
use Easybdit\LaravelMikrotik\Models\MikrotikRule;
use Easybdit\LaravelMikrotik\Models\MikrotikSnapshot;
use Easybdit\LaravelMikrotik\Monitoring\IncidentManager;
use Easybdit\LaravelMikrotik\Monitoring\RuleEvaluator;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IncidentManagerTest extends TestCase
{
    use RefreshDatabase;

    private RuleEvaluator $evaluator;
    private IncidentManager $incidents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new RuleEvaluator();
        $this->incidents = new IncidentManager();
    }

    private function makeSnapshot(string $connection, int $cpuLoad, ?Carbon $capturedAt = null): MikrotikSnapshot
    {
        return MikrotikSnapshot::query()->create([
            'connection'  => $connection,
            'captured_at' => $capturedAt ?? now(),
            'resource'    => ['cpu_load' => $cpuLoad],
            'health'      => [],
            'interfaces'  => [],
        ]);
    }

    /** @return array{triggered: list<MikrotikAlert>, justResolved: \Illuminate\Database\Eloquent\Collection<int, MikrotikAlert>} */
    private function evaluateAndDiff(MikrotikSnapshot $snapshot): array
    {
        $activeIdsBefore = MikrotikAlert::query()->forConnection($snapshot->connection)->active()->pluck('id');
        $triggered = $this->evaluator->evaluate($snapshot);
        $justResolved = MikrotikAlert::query()
            ->whereIn('id', $activeIdsBefore->diff(
                MikrotikAlert::query()->forConnection($snapshot->connection)->active()->pluck('id')
            ))
            ->get();

        return ['triggered' => $triggered, 'justResolved' => $justResolved];
    }

    public function test_first_matching_alert_opens_an_incident(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'High CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 80,
        ]);

        $result = $this->evaluateAndDiff($this->makeSnapshot('default', 95));
        $transitions = $this->incidents->reconcile($result['triggered'], $result['justResolved']);

        $this->assertCount(1, $transitions['opened']);
        $this->assertCount(0, $transitions['resolved']);
        $this->assertDatabaseCount('mikrotik_incidents', 1);

        $incident = $transitions['opened'][0];
        $this->assertSame(MikrotikIncident::STATUS_OPEN, $incident->status);
        $this->assertSame('default', $incident->connection);
        $this->assertSame('resource.cpu_load', $incident->metric);
        $this->assertNotNull($incident->opened_at);
        $this->assertNull($incident->resolved_at);
        $this->assertSame($result['triggered'][0]->id, $incident->first_alert_id);
        $this->assertSame($incident->id, $result['triggered'][0]->fresh()->incident_id);
    }

    public function test_repeated_matching_snapshots_reuse_the_same_incident_not_a_new_one(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'High CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 80,
        ]);

        $r1 = $this->evaluateAndDiff($this->makeSnapshot('default', 95));
        $t1 = $this->incidents->reconcile($r1['triggered'], $r1['justResolved']);
        $this->assertCount(1, $t1['opened']);

        // Two more matching snapshots -- P5 dedup means no new alert, so
        // no new incident either.
        $r2 = $this->evaluateAndDiff($this->makeSnapshot('default', 95));
        $t2 = $this->incidents->reconcile($r2['triggered'], $r2['justResolved']);
        $r3 = $this->evaluateAndDiff($this->makeSnapshot('default', 95));
        $t3 = $this->incidents->reconcile($r3['triggered'], $r3['justResolved']);

        $this->assertCount(0, $t2['opened']);
        $this->assertCount(0, $t2['resolved']);
        $this->assertCount(0, $t3['opened']);
        $this->assertCount(0, $t3['resolved']);
        $this->assertDatabaseCount('mikrotik_incidents', 1);
        $this->assertSame(MikrotikIncident::STATUS_OPEN, MikrotikIncident::query()->sole()->status);
    }

    public function test_condition_clearing_resolves_both_the_alert_and_the_incident(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'High CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 80,
        ]);

        $r1 = $this->evaluateAndDiff($this->makeSnapshot('default', 95));
        $this->incidents->reconcile($r1['triggered'], $r1['justResolved']);

        $r2 = $this->evaluateAndDiff($this->makeSnapshot('default', 10));
        $t2 = $this->incidents->reconcile($r2['triggered'], $r2['justResolved']);

        $this->assertCount(0, $t2['opened']);
        $this->assertCount(1, $t2['resolved']);

        $alert = MikrotikAlert::query()->sole();
        $this->assertSame(MikrotikAlert::STATUS_RESOLVED, $alert->status);

        $incident = MikrotikIncident::query()->sole();
        $this->assertSame(MikrotikIncident::STATUS_RESOLVED, $incident->status);
        $this->assertNotNull($incident->resolved_at);
        $this->assertNull($incident->open_rule_id);
    }

    public function test_condition_returning_after_resolution_opens_a_new_incident_not_a_revived_one(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'High CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 80,
        ]);

        $r1 = $this->evaluateAndDiff($this->makeSnapshot('default', 95));
        $this->incidents->reconcile($r1['triggered'], $r1['justResolved']);

        $r2 = $this->evaluateAndDiff($this->makeSnapshot('default', 10));
        $this->incidents->reconcile($r2['triggered'], $r2['justResolved']);

        $r3 = $this->evaluateAndDiff($this->makeSnapshot('default', 95));
        $t3 = $this->incidents->reconcile($r3['triggered'], $r3['justResolved']);

        $this->assertCount(1, $t3['opened']);
        $this->assertDatabaseCount('mikrotik_incidents', 2);

        $incidents = MikrotikIncident::query()->orderBy('id')->get();
        $this->assertSame(MikrotikIncident::STATUS_RESOLVED, $incidents[0]->status);
        $this->assertSame(MikrotikIncident::STATUS_OPEN, $incidents[1]->status);
        $this->assertNotSame($incidents[0]->id, $incidents[1]->id);
    }

    public function test_missing_metric_does_not_resolve_the_incident(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'CPU too hot', 'metric' => 'health.cpu-temperature.value',
            'operator' => '>=', 'threshold' => 70,
        ]);

        $snapshot1 = MikrotikSnapshot::query()->create([
            'connection' => 'default', 'captured_at' => now(),
            'resource' => [], 'health' => ['cpu-temperature' => ['value' => 72]], 'interfaces' => [],
        ]);
        $r1 = $this->evaluateAndDiff($snapshot1);
        $this->incidents->reconcile($r1['triggered'], $r1['justResolved']);

        // Sensor absent from a later snapshot -- skipped by RuleEvaluator,
        // must not resolve the alert nor the incident.
        $snapshot2 = MikrotikSnapshot::query()->create([
            'connection' => 'default', 'captured_at' => now(),
            'resource' => [], 'health' => [], 'interfaces' => [],
        ]);
        $r2 = $this->evaluateAndDiff($snapshot2);
        $t2 = $this->incidents->reconcile($r2['triggered'], $r2['justResolved']);

        $this->assertCount(0, $t2['resolved']);
        $this->assertSame(MikrotikIncident::STATUS_OPEN, MikrotikIncident::query()->sole()->status);
    }

    public function test_multiple_rules_open_independent_incidents(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'High CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 80,
        ]);
        MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'Interface errors', 'metric' => 'interfaces.ether1.rx_error',
            'operator' => '>', 'threshold' => 5,
        ]);

        $snapshot = MikrotikSnapshot::query()->create([
            'connection' => 'default', 'captured_at' => now(),
            'resource' => ['cpu_load' => 95], 'health' => [], 'interfaces' => ['ether1' => ['rx_error' => 12]],
        ]);
        $r1 = $this->evaluateAndDiff($snapshot);
        $t1 = $this->incidents->reconcile($r1['triggered'], $r1['justResolved']);

        $this->assertCount(2, $t1['opened']);
        $this->assertDatabaseCount('mikrotik_incidents', 2);
    }

    public function test_a_global_rule_opens_separate_incidents_per_connection(): void
    {
        MikrotikRule::query()->create([
            'connection' => null, 'name' => 'Global high CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 80,
        ]);

        $rDefault = $this->evaluateAndDiff($this->makeSnapshot('default', 95));
        $tDefault = $this->incidents->reconcile($rDefault['triggered'], $rDefault['justResolved']);

        $rBranch = $this->evaluateAndDiff($this->makeSnapshot('branch-01', 95));
        $tBranch = $this->incidents->reconcile($rBranch['triggered'], $rBranch['justResolved']);

        $this->assertCount(1, $tDefault['opened']);
        $this->assertCount(1, $tBranch['opened']);
        $this->assertDatabaseCount('mikrotik_incidents', 2);
        $this->assertSame(
            2,
            MikrotikIncident::query()->pluck('connection')->unique()->count()
        );
    }

    public function test_incidents_are_isolated_per_connection_for_a_connection_scoped_rule(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'High CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 80,
        ]);

        // branch-01 has no rule scoped to it and no global rule -- nothing
        // should ever be opened for it.
        $r = $this->evaluateAndDiff($this->makeSnapshot('branch-01', 95));
        $t = $this->incidents->reconcile($r['triggered'], $r['justResolved']);

        $this->assertCount(0, $t['opened']);
        $this->assertDatabaseCount('mikrotik_incidents', 0);
    }

    public function test_concurrent_incident_creation_is_rejected_at_the_database_level(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'High CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 80,
        ]);

        $r1 = $this->evaluateAndDiff($this->makeSnapshot('default', 95));
        $alert = $r1['triggered'][0];

        // Simulate a second, concurrent process that already opened the
        // incident for this exact rule+connection an instant earlier.
        $this->assertDatabaseCount('mikrotik_incidents', 0);
        MikrotikIncident::query()->create([
            'rule_id' => $alert->rule_id, 'first_alert_id' => $alert->id, 'connection' => 'default',
            'metric' => $alert->metric, 'status' => MikrotikIncident::STATUS_OPEN,
            'open_rule_id' => $alert->rule_id, 'opened_at' => now(),
        ]);

        // A raw duplicate insert must be rejected by the unique index
        // itself -- this is the real database-level guarantee, not just
        // IncidentManager choosing not to insert one.
        $this->expectException(QueryException::class);
        DB::table('mikrotik_incidents')->insert([
            'rule_id' => $alert->rule_id, 'first_alert_id' => $alert->id, 'connection' => 'default',
            'metric' => $alert->metric, 'status' => MikrotikIncident::STATUS_OPEN,
            'open_rule_id' => $alert->rule_id, 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_incident_manager_gracefully_handles_an_already_open_incident_from_another_process(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default', 'name' => 'High CPU', 'metric' => 'resource.cpu_load',
            'operator' => '>', 'threshold' => 80,
        ]);

        $r1 = $this->evaluateAndDiff($this->makeSnapshot('default', 95));
        $alert = $r1['triggered'][0];

        // Pre-create the incident, as if another process's IncidentManager
        // already won the race for this exact rule+connection.
        MikrotikIncident::query()->create([
            'rule_id' => $alert->rule_id, 'first_alert_id' => $alert->id, 'connection' => 'default',
            'metric' => $alert->metric, 'status' => MikrotikIncident::STATUS_OPEN,
            'open_rule_id' => $alert->rule_id, 'opened_at' => now(),
        ]);

        // reconcile() must not throw and must not create a duplicate.
        $transitions = $this->incidents->reconcile([$alert], collect());

        $this->assertCount(0, $transitions['opened']);
        $this->assertDatabaseCount('mikrotik_incidents', 1);
    }
}
