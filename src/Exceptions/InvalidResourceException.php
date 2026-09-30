<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Exceptions;

/**
 * Thrown when a typed write resource (e.g. Resources\IpAddressResource,
 * P13) is given a payload missing a field RouterOS requires, or an
 * unsafe/invalid item identifier — validated by this package before a
 * request is ever sent, never for anything RouterOS itself rejected
 * (see RouterOsException for that).
 */
class InvalidResourceException extends MikrotikException
{
    public static function missingRequiredField(string $resource, string $field): self
    {
        return new self("Creating a MikroTik {$resource} requires the '{$field}' field.");
    }
}
