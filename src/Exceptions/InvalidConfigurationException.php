<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Exceptions;

/**
 * Thrown for problems in this package's own configuration (an unknown
 * connection name, a connection missing a required key, an unsupported
 * transport) — never for anything the router itself said or did.
 */
class InvalidConfigurationException extends MikrotikException
{
    public static function unknownConnection(string $name): self
    {
        return new self("MikroTik connection [{$name}] is not defined in config/mikrotik.php.");
    }

    public static function missingKey(string $connection, string $key): self
    {
        return new self("MikroTik connection [{$connection}] is missing required config key [{$key}].");
    }

    public static function unsupportedTransport(string $connection, string $transport): self
    {
        return new self(
            "MikroTik connection [{$connection}] requests unsupported transport [{$transport}]. Only 'rest' is supported in this version."
        );
    }
}
