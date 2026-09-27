<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Connection;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\HealthReading;
use Easybdit\LaravelMikrotik\DTO\InterfaceCollection;
use Easybdit\LaravelMikrotik\DTO\LogCollection;
use Easybdit\LaravelMikrotik\DTO\RouterResource;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;

/**
 * One configured, named MikroTik router. This is the object returned by
 * Mikrotik::connection('name') and is the package's main public API
 * surface — consumers never touch a Transport or ResponseNormalizer
 * directly.
 */
final class RouterConnection
{
    public function __construct(
        private readonly string $name,
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    /** The connection name this instance was resolved for (e.g. "office-main"). */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Router identity/resource info (RouterOS `/system/resource`).
     */
    public function resource(): RouterResource
    {
        return $this->normalizer->normalizeResource($this->transport->get('/system/resource'));
    }

    /**
     * Hardware health sensors (RouterOS `/system/health`). The returned
     * set of sensors varies by device — see HealthReading/HealthSensor.
     */
    public function health(): HealthReading
    {
        return $this->normalizer->normalizeHealth($this->transport->get('/system/health'));
    }

    /**
     * Router interfaces (RouterOS `/interface`), including cumulative
     * traffic counters (bytes/packets since the interface last reset —
     * typically the last reboot). This is NOT a live/instantaneous
     * throughput reading: RouterOS's REST API has no supported way to
     * run a continuous "monitor" command, so a live-rate API is
     * deliberately not offered here. To compute throughput, poll this
     * method twice and divide the counter delta by the elapsed interval.
     */
    public function interfaces(): InterfaceCollection
    {
        $identity = $this->transport->get('/interface');
        $stats = $this->transport->post('/interface/print', ['stats-detail' => '']);

        return $this->normalizer->normalizeInterfaces($identity, $stats);
    }

    /**
     * Router log entries (RouterOS `/log`), most-recent-first-or-last
     * exactly as RouterOS returns them (this package does not reorder
     * them). $filter is sent as simple equality query parameters (e.g.
     * ['topics' => 'critical']) — RouterOS REST's documented GET
     * query-string filtering form. This does not support RouterOS's
     * `~` (contains/regex) console filter operator.
     *
     * @param array<string, scalar> $filter
     */
    public function logs(array $filter = []): LogCollection
    {
        return $this->normalizer->normalizeLogs($this->transport->get('/log', $filter));
    }
}
