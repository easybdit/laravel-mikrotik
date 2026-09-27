<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Exceptions;

/**
 * Thrown when the router returned a 2xx response, but the body was not
 * valid JSON, or was not shaped the way this package expects (e.g. not
 * a JSON array/object at all). Never thrown just because a *field* is
 * missing or unrecognised — that case is handled gracefully by the
 * DTOs/normalizer instead, per this package's normalization contract.
 */
class MalformedResponseException extends MikrotikException
{
    public static function invalidJson(string $host, string $path): self
    {
        return new self(
            "MikroTik router at [{$host}] returned a response for [{$path}] that could not be parsed as JSON."
        );
    }
}
