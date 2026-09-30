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
     * $query is sent as simple equality filters (RouterOS REST's
     * documented GET query-string form, e.g. "?type=ether"), for
     * endpoints that support it. Omit it for endpoints that don't need
     * filtering — this is what every P1 caller already does, unchanged.
     *
     * @param array<string, scalar> $query
     * @return array<string|int, mixed> Decoded response body.
     *
     * @throws ConnectionException        Router unreachable / timed out.
     * @throws AuthenticationException    Credentials rejected.
     * @throws RouterOsException          RouterOS returned an error.
     * @throws MalformedResponseException Response could not be parsed.
     */
    public function get(string $path, array $query = []): array;

    /**
     * Run a console command against a path via RouterOS REST's POST
     * mechanism (the only way to pass command arguments RouterOS's GET
     * query-string filtering can't express, e.g. "/interface print
     * stats-detail" — POST /interface/print with body {"stats-detail":""}).
     *
     * @param array<string, scalar> $body
     * @return array<string|int, mixed> Decoded response body.
     *
     * @throws ConnectionException        Router unreachable / timed out.
     * @throws AuthenticationException    Credentials rejected.
     * @throws RouterOsException          RouterOS returned an error.
     * @throws MalformedResponseException Response could not be parsed.
     */
    public function post(string $path, array $body = []): array;

    /**
     * Create a new record (maps to RouterOS's "add"). $path has no
     * identifier in it; RouterOS assigns one and includes it in the
     * response (source: help.mikrotik.com "REST API" — PUT = add).
     *
     * P12 foundation only — nothing in this package calls this publicly
     * yet. Implementations must never apply the read-path retry
     * configuration to this method (see RestTransport's docblock for
     * why: an ambiguous failure after a write already reached RouterOS
     * cannot be safely retried).
     *
     * @param array<string, scalar> $body
     * @return array<string|int, mixed> Decoded response body.
     *
     * @throws ConnectionException        Router unreachable / timed out.
     * @throws AuthenticationException    Credentials rejected.
     * @throws RouterOsException          RouterOS returned an error.
     * @throws MalformedResponseException Response could not be parsed.
     */
    public function put(string $path, array $body = []): array;

    /**
     * Update an existing record by identifier (maps to RouterOS's
     * "set"). $path includes the target's ".id" or name (source:
     * help.mikrotik.com "REST API" — PATCH = set, identifier in the URL).
     *
     * P12 foundation only — see put()'s docblock re: retry.
     *
     * @param array<string, scalar> $body
     * @return array<string|int, mixed> Decoded response body.
     *
     * @throws ConnectionException        Router unreachable / timed out.
     * @throws AuthenticationException    Credentials rejected.
     * @throws RouterOsException          RouterOS returned an error.
     * @throws MalformedResponseException Response could not be parsed.
     */
    public function patch(string $path, array $body = []): array;

    /**
     * Remove an existing record by identifier (maps to RouterOS's
     * "remove"). $path includes the target's ".id" or name (source:
     * help.mikrotik.com "REST API" — DELETE = remove, identifier in the
     * URL, no request body).
     *
     * P12 foundation only — see put()'s docblock re: retry.
     *
     * @return array<string|int, mixed> Decoded response body.
     *
     * @throws ConnectionException        Router unreachable / timed out.
     * @throws AuthenticationException    Credentials rejected.
     * @throws RouterOsException          RouterOS returned an error.
     * @throws MalformedResponseException Response could not be parsed.
     */
    public function delete(string $path): array;
}
