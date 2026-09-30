<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Monitoring;

use Easybdit\LaravelMikrotik\Connection\ConnectionManager;
use Easybdit\LaravelMikrotik\Models\MikrotikSnapshot;
use Illuminate\Support\Carbon;

/**
 * Captures a named connection's current resource/health/interface state
 * (via RouterConnection::resource()/health()/interfaces() — the same
 * P1-P3 REST calls those methods already make) and persists it as one
 * MikrotikSnapshot row. Purely additive: it does not change those
 * methods' behavior or signatures, and nothing in P1-P3 depends on it.
 *
 * Requires this package's "mikrotik-migrations" to have been published
 * and run — an application that never does so is unaffected by this
 * class existing.
 *
 * @throws \Easybdit\LaravelMikrotik\Exceptions\MikrotikException Whatever resource()/health()/interfaces() themselves throw.
 */
final class SnapshotRecorder
{
    public function __construct(private readonly ConnectionManager $connections)
    {
    }

    public function record(?string $connectionName = null): MikrotikSnapshot
    {
        $connection = $this->connections->connection($connectionName);

        $resource = $connection->resource();
        $health = $connection->health();
        $interfaces = $connection->interfaces();

        return MikrotikSnapshot::query()->create([
            'connection'  => $connection->name(),
            'captured_at' => Carbon::now(),
            'resource'    => $resource->toArray(),
            'health'      => $health->toArray(),
            'interfaces'  => $interfaces->toArray(),
        ]);
    }
}
