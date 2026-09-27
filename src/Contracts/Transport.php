<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Contracts;

use Easybdit\LaravelMikrotik\Exceptions\AuthenticationException;
use Easybdit\LaravelMikrotik\Exceptions\ConnectionException;
use Easybdit\LaravelMikrotik\Exceptions\MalformedResponseException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;

/**
 * A Transport knows how to fetch a RouterOS console path (e.g.
 * "/system/resource") and return it as a decoded array, regardless of
 * the wire protocol underneath (REST today; a future binary API
 * transport implements the same contract without any change to
 * RouterConnection or the resource/health layers).
 */
interface Transport
{
    /**
     * Fetch a read-only console resource (maps to RouterOS's "print").
     *
     * @return array<string|int, mixed> Decoded response body.
     *
     * @throws ConnectionException        Router unreachable / timed out.
     * @throws AuthenticationException    Credentials rejected.
     * @throws RouterOsException          RouterOS returned an error.
     * @throws MalformedResponseException Response could not be parsed.
     */
    public function get(string $path): array;
}
