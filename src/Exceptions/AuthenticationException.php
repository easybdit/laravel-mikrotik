<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Exceptions;

/**
 * Thrown when the router was reached but rejected the supplied
 * credentials (HTTP 401 / 403 from the RouterOS REST service).
 *
 * The message intentionally never includes the username or password
 * that was rejected.
 */
class AuthenticationException extends MikrotikException
{
    public static function rejected(string $host, int $statusCode): self
    {
        return new self(
            "MikroTik router at [{$host}] rejected the configured credentials (HTTP {$statusCode})."
        );
    }
}
