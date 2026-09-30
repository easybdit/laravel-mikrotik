<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Models\MikrotikAlert;
use Easybdit\LaravelMikrotik\Models\MikrotikRule;
use Easybdit\LaravelMikrotik\Models\MikrotikSnapshot;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

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
}
