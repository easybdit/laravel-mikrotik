<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Exceptions;

/**
 * Thrown when the router was reached, authentication succeeded, but
 * RouterOS itself returned an error for the requested command.
 *
 * RouterOS REST errors have the shape:
 *   {"error": 400, "message": "Bad Request", "detail": "..."}
 * (see help.mikrotik.com "REST API" — Errors section). This exception
 * preserves that structure without ever containing request credentials,
 * since RouterOS's own error bodies do not echo them back.
 */
class RouterOsException extends MikrotikException
{
    public function __construct(
        string $message,
        private readonly int $routerOsErrorCode,
        private readonly ?string $routerOsDetail = null,
    ) {
        parent::__construct($message);
    }

    public static function fromErrorBody(string $host, int $httpStatus, array $body): self
    {
        $message = $body['message'] ?? 'RouterOS returned an error';
        $detail  = isset($body['detail']) ? (string) $body['detail'] : null;

        return new self(
            "MikroTik router at [{$host}] returned an error: {$message} (HTTP {$httpStatus}).",
            $httpStatus,
            $detail
        );
    }

    public function getRouterOsErrorCode(): int
    {
        return $this->routerOsErrorCode;
    }

    public function getRouterOsDetail(): ?string
    {
        return $this->routerOsDetail;
    }
}
