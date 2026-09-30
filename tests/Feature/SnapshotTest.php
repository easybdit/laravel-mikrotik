<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Models\MikrotikSnapshot;
use Easybdit\LaravelMikrotik\Monitoring\SnapshotRecorder;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class SnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const RESOURCE_URL = 'router.test/rest/system/resource';
    private const HEALTH_URL = 'router.test/rest/system/health';
    private const INTERFACE_URL = 'router.test/rest/interface';

    public function test_record_persists_a_snapshot_of_live_router_data(): void
    {
        Http::fake([
            self::RESOURCE_URL => Http::response([[
                'architecture-name' => 'arm64',
                'cpu-load'          => '3',
                'free-memory'       => '1503133696',
                'version'           => '7.15 (stable)',
            ]], 200),
            self::HEALTH_URL => Http::response([
                ['name' => 'cpu-temperature', 'value' => '43', 'type' => 'C'],
            ], 200),
            self::INTERFACE_URL => Http::response([[
                'name'    => 'ether1',
                'type'    => 'ether',
                'running' => 'true',
                'rx-byte' => '205164277',
                'tx-byte' => '98234',
            ]], 200),
        ]);

        $snapshot = $this->app->make(SnapshotRecorder::class)->record();

        $this->assertDatabaseCount('mikrotik_snapshots', 1);
        $this->assertSame('default', $snapshot->connection);
        $this->assertNotNull($snapshot->captured_at);
        $this->assertSame('7.15 (stable)', $snapshot->resource['version']);
        $this->assertSame(3, $snapshot->resource['cpu_load']);
        $this->assertSame(43, $snapshot->health['cpu-temperature']['value']);
        $this->assertSame(205164277, $snapshot->interfaces['ether1']['rx_byte']);
    }

    public function test_record_accepts_an_explicit_connection_name(): void
    {
        Http::fake([
            self::RESOURCE_URL => Http::response([[]], 200),
            self::HEALTH_URL => Http::response([], 200),
            self::INTERFACE_URL => Http::response([], 200),
        ]);

        $snapshot = $this->app->make(SnapshotRecorder::class)->record('default');

        $this->assertSame('default', $snapshot->connection);
    }

    public function test_empty_router_responses_persist_as_empty_arrays_not_an_error(): void
    {
        Http::fake([
            self::RESOURCE_URL => Http::response([], 200),
            self::HEALTH_URL => Http::response([], 200),
            self::INTERFACE_URL => Http::response([], 200),
        ]);

        $snapshot = $this->app->make(SnapshotRecorder::class)->record();

        $this->assertNull($snapshot->resource['version']);
        $this->assertSame([], $snapshot->health);
        $this->assertSame([], $snapshot->interfaces);
    }

    public function test_snapshots_can_be_scoped_to_a_connection(): void
    {
        Http::fake([
            self::RESOURCE_URL => Http::response([[]], 200),
            self::HEALTH_URL => Http::response([], 200),
            self::INTERFACE_URL => Http::response([], 200),
        ]);

        $this->app->make(SnapshotRecorder::class)->record();

        $this->assertSame(1, MikrotikSnapshot::query()->forConnection('default')->count());
        $this->assertSame(0, MikrotikSnapshot::query()->forConnection('other')->count());
    }
}
