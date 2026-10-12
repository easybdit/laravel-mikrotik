<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Monitoring;

use Easybdit\LaravelMikrotik\Connection\ConnectionManager;
use Easybdit\LaravelMikrotik\Exceptions\MikrotikException;
use Easybdit\LaravelMikrotik\Models\MikrotikSnapshot;
use Illuminate\Support\Carbon;

/**
 * Captures a named connection's current resource/health/interface state
 * (via RouterConnection::pollAll() since P24 — the same P1-P3 REST
 * calls resource()/health()/interfaces() already make, now issued
 * concurrently instead of sequentially, see pollAll()'s docblock) and
 * persists it as one MikrotikSnapshot row. Purely additive: it does not
 * change resource()/health()/interfaces()' own behavior or signatures,
 * and record()'s own return value/behavior is unchanged by the P24
 * change — only how the underlying HTTP requests are issued.
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

        $data = $connection->pollAll();

        return MikrotikSnapshot::query()->create([
            'connection'  => $connection->name(),
            'captured_at' => Carbon::now(),
            'resource'    => $data['resource']->toArray(),
            'health'      => $data['health']->toArray(),
            'interfaces'  => $data['interfaces']->toArray(),
        ]);
    }

    /**
     * P24: records a snapshot for each of $names (default: every
     * configured connection), one router at a time. A router that fails
     * to poll yields its MikrotikException in the result instead of a
     * snapshot, and never prevents the others from being recorded.
     * Database errors are not caught.
     *
     * @param list<string>|null $names
     * @return array<string, MikrotikSnapshot|MikrotikException>
     */
    public function recordMany(?array $names = null): array
    {
        $results = [];

        foreach ($this->connections->pollMany($names) as $name => $poll) {
            $results[$name] = $poll->ok()
                ? MikrotikSnapshot::query()->create([
                    'connection'  => $name,
                    'captured_at' => Carbon::now(),
                    'resource'    => $poll->data['resource']->toArray(),
                    'health'      => $poll->data['health']->toArray(),
                    'interfaces'  => $poll->data['interfaces']->toArray(),
                ])
                : $poll->error;
        }

        return $results;
    }
}
