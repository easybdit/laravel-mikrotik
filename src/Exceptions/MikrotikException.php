<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Exceptions;

use RuntimeException;

/**
 * Base exception for every exception this package throws.
 *
 * Catching this type catches any failure originating from this package,
 * regardless of cause (transport, authentication, RouterOS-side error,
 * or an unparseable response).
 *
 * IMPORTANT: subclasses must never interpolate credentials (username,
 * password, tokens) into an exception message. Only non-secret context
 * (host, connection name, HTTP status) is safe to include.
 */
class MikrotikException extends RuntimeException
{
}
