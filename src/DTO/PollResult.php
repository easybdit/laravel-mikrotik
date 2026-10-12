<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

use Easybdit\LaravelMikrotik\Exceptions\MikrotikException;

/**
 * Outcome of polling one router within a multi-router call (P24):
 * either $data (the same array RouterConnection::pollAll() returns) or
 * the $error that router raised. One router failing never prevents the
 * others from being polled.
 */
final class PollResult
{
    /**
     * @param array{resource: RouterResource, health: HealthReading, interfaces: InterfaceCollection}|null $data
     */
    public function __construct(
        public readonly string $connection,
        public readonly ?array $data,
        public readonly ?MikrotikException $error,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null;
    }
}
