<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Exceptions;

/**
 * Thrown when a write is refused by this package's own safeguard
 * (P25), e.g. deleting or disabling a default route without explicitly
 * opting in. Nothing was sent to the router that would change it.
 */
class UnsafeOperationException extends MikrotikException
{
    public static function activeUser(string $action, string $username): self
    {
        return new self(
            "Refusing to {$action} router user [{$username}]: it is the account this connection logs in as, "
                . 'so doing so could lock this connection out. Pass allowActiveUser: true to proceed deliberately.'
        );
    }

    public static function defaultRoute(string $action, string $id): self
    {
        return new self(
            "Refusing to {$action} route [{$id}]: it is a default route (0.0.0.0/0), and changing it can cut off "
                . 'the router\'s connectivity. Pass allowDefaultRoute: true to proceed deliberately.'
        );
    }
}
