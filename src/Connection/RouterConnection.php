<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Connection;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\HealthReading;
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
}
