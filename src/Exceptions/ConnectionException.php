<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Exceptions;

/**
 * Thrown when the router could not be reached at all: DNS failure,
 * connection refused, TLS handshake failure, or a request timeout.
 *
 * This is distinct from AuthenticationException (router was reached,
 * credentials were rejected) and RouterOsException (router was reached,
 * credentials were fine, RouterOS itself returned an error).
 */
class ConnectionException extends MikrotikException
{
    public static function unreachable(string $host, ?\Throwable $previous = null): self
    {
        return new self("Unable to connect to MikroTik router at [{$host}].", 0, $previous);
    }

    public static function timedOut(string $host, int $timeoutSeconds): self
    {
        return new self(
            "Connection to MikroTik router at [{$host}] timed out after {$timeoutSeconds}s."
        );
    }
}
