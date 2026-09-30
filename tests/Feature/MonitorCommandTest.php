<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Models\MikrotikAlert;
use Easybdit\LaravelMikrotik\Models\MikrotikIncident;
use Easybdit\LaravelMikrotik\Models\MikrotikRule;
use Easybdit\LaravelMikrotik\Models\MikrotikSnapshot;
use Easybdit\LaravelMikrotik\Notifications\MikrotikAlertNotification;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

class MonitorCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fakeDefaultConnection(int $cpuLoad = 95): void
    {
        Http::fake([
            'router.test/rest/system/resource' => Http::response([[
                'cpu-load' => (string) $cpuLoad,
                'version'  => '7.15 (stable)',
            ]], 200),
            'router.test/rest/system/health'    => Http::response([], 200),
            'router.test/rest/interface'         => Http::response([], 200),
        ]);
    }

    private function addBranchConnection(): void
    {
        config()->set('mikrotik.connections.branch-01', [
            'transport'  => 'rest',
            'host'       => 'branch.test',
            'port'       => 443,
            'username'   => 'admin',
            'password'   => 'branch-secret',
            'verify_tls' => true,
            'timeout'    => 5,
        ]);
    }

    public function test_monitor_records_a_snapshot_and_evaluates_rules(): void
    {
        $this->fakeDefaultConnection(95);

        MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'High CPU load',
            'metric'     => 'resource.cpu_load',
            'operator'   => '>',
            'threshold'  => 90,
        ]);

        $this->artisan('mikrotik:monitor')->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('mikrotik_snapshots', 1);
        $this->assertDatabaseCount('mikrotik_alerts', 1);
        $this->assertSame('default', MikrotikSnapshot::query()->sole()->connection);
    }

    public function test_monitor_targets_a_specific_connection_via_option(): void
    {
        $this->addBranchConnection();
        $this->fakeDefaultConnection();
        Http::fake([
            'branch.test/rest/system/resource' => Http::response([['cpu-load' => '5']], 200),
            'branch.test/rest/system/health'    => Http::response([], 200),
            'branch.test/rest/interface'         => Http::response([], 200),
        ]);

        $this->artisan('mikrotik:monitor', ['--connection' => ['branch-01']])
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('mikrotik_snapshots', 1);
        $this->assertSame('branch-01', MikrotikSnapshot::query()->sole()->connection);
    }

    public function test_monitor_continues_past_a_connection_that_fails(): void
    {
        $this->addBranchConnection();
        $this->fakeDefaultConnection();
        Http::fake([
            'branch.test/rest/system/resource' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $this->artisan('mikrotik:monitor')->assertExitCode(Command::SUCCESS);

        // default succeeded, branch-01 failed -- only default's snapshot exists.
        $this->assertDatabaseCount('mikrotik_snapshots', 1);
        $this->assertSame('default', MikrotikSnapshot::query()->sole()->connection);
    }

    public function test_monitor_continues_past_a_connection_that_fails_unexpectedly(): void
    {
        // P11: the per-connection catch was broadened from MikrotikException
        // to Throwable -- a genuinely unexpected failure (not a MikroTik-
        // specific one) for one connection must still not stop the others.
        $this->addBranchConnection();
        $this->fakeDefaultConnection();
        Http::fake([
            'branch.test/rest/system/resource' => function () {
                throw new \RuntimeException('unexpected failure unrelated to MikroTik');
            },
            // P24: pollAll() fetches resource/health/interfaces
            // concurrently, so these two must have a fake response too
            // (previously, sequential execution never reached them,
            // since resource() always threw first) -- their success is
            // irrelevant to this test, only resource()'s failure is.
            'branch.test/rest/system/health' => Http::response([], 200),
            'branch.test/rest/interface'     => Http::response([], 200),
        ]);

        $this->artisan('mikrotik:monitor')
            ->assertExitCode(Command::SUCCESS)
            ->expectsOutputToContain('unexpected failure unrelated to MikroTik');

        $this->assertDatabaseCount('mikrotik_snapshots', 1);
        $this->assertSame('default', MikrotikSnapshot::query()->sole()->connection);
    }

    public function test_monitor_fails_when_no_connections_are_configured(): void
    {
        config()->set('mikrotik.connections', []);

        $this->artisan('mikrotik:monitor')->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('mikrotik_snapshots', 0);
    }

    public function test_monitor_can_run_repeatedly_without_error_or_duplicate_alerts(): void
    {
        $this->fakeDefaultConnection(95);

        MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'High CPU load',
            'metric'     => 'resource.cpu_load',
            'operator'   => '>',
            'threshold'  => 90,
        ]);

        $this->artisan('mikrotik:monitor')->assertExitCode(Command::SUCCESS);
        $this->artisan('mikrotik:monitor')->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('mikrotik_snapshots', 2);
        // The condition never stopped matching between runs -- P5's
        // dedup keeps this at one active alert, not two.
        $this->assertDatabaseCount('mikrotik_alerts', 1);
        $this->assertSame(MikrotikAlert::STATUS_TRIGGERED, MikrotikAlert::query()->sole()->status);
    }

    public function test_monitor_notifies_on_newly_triggered_and_newly_resolved_alerts(): void
    {
        config()->set('mikrotik.notifications.webhook', ['enabled' => true, 'url' => 'https://hooks.test/mikrotik']);
        Notification::fake();

        MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'High CPU load',
            'metric'     => 'resource.cpu_load',
            'operator'   => '>',
            'threshold'  => 90,
        ]);

        // A single stateful fake for the whole test: Http::fake() resolves
        // a URL to its FIRST matching stub, so a second Http::fake() call
        // for the same URL would never actually take effect -- cpu_load
        // must vary within one fake, not across two separate fake() calls.
        $cpuLoads = [95, 10];
        $call = 0;
        Http::fake([
            'router.test/rest/system/resource' => function () use ($cpuLoads, &$call) {
                $load = $cpuLoads[$call++];

                return Http::response([['cpu-load' => (string) $load]], 200);
            },
            'router.test/rest/system/health'    => Http::response([], 200),
            'router.test/rest/interface'         => Http::response([], 200),
        ]);

        $this->artisan('mikrotik:monitor')->assertExitCode(Command::SUCCESS);

        Notification::assertSentOnDemandTimes(MikrotikAlertNotification::class, 1);

        $this->artisan('mikrotik:monitor')->assertExitCode(Command::SUCCESS);

        // 1 for the trigger, 1 more for the resolution.
        Notification::assertSentOnDemandTimes(MikrotikAlertNotification::class, 2);
    }

    public function test_monitor_opens_and_resolves_an_incident_alongside_the_alert(): void
    {
        MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'High CPU load',
            'metric'     => 'resource.cpu_load',
            'operator'   => '>',
            'threshold'  => 90,
        ]);

        $cpuLoads = [95, 10];
        $call = 0;
        Http::fake([
            'router.test/rest/system/resource' => function () use ($cpuLoads, &$call) {
                return Http::response([['cpu-load' => (string) $cpuLoads[$call++]]], 200);
            },
            'router.test/rest/system/health'    => Http::response([], 200),
            'router.test/rest/interface'         => Http::response([], 200),
        ]);

        $this->artisan('mikrotik:monitor')->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('mikrotik_incidents', 1);
        $incident = MikrotikIncident::query()->sole();
        $this->assertSame(MikrotikIncident::STATUS_OPEN, $incident->status);
        $this->assertSame(MikrotikAlert::query()->sole()->id, $incident->first_alert_id);

        $this->artisan('mikrotik:monitor')->assertExitCode(Command::SUCCESS);

        // Same incident, not a new one -- resolved in place.
        $this->assertDatabaseCount('mikrotik_incidents', 1);
        $this->assertSame(MikrotikIncident::STATUS_RESOLVED, $incident->fresh()->status);
    }

    public function test_monitor_does_not_notify_when_nothing_is_configured(): void
    {
        Notification::fake();

        MikrotikRule::query()->create([
            'connection' => 'default',
            'name'       => 'High CPU load',
            'metric'     => 'resource.cpu_load',
            'operator'   => '>',
            'threshold'  => 90,
        ]);

        $this->fakeDefaultConnection(95);
        $this->artisan('mikrotik:monitor')->assertExitCode(Command::SUCCESS);

        Notification::assertNothingSent();
    }
}
