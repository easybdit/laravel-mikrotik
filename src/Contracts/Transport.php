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
     * Fetches multiple read-only paths concurrently (P24), for reducing
     * total wall-clock time when several independent reads are needed
     * together (e.g. RouterConnection::pollAll()) — measured directly
     * against a real device to roughly halve total latency for three
     * such reads versus issuing them sequentially. Every result is
     * mapped through the same exception logic get() already uses; a
     * failure on any one request throws immediately (the same
     * all-or-nothing contract get() already has for a single request).
     * Supports the same opt-in retry configuration get()/post() already
     * have.
     *
     * @param array<string, array{path: string, query?: array<string, scalar>}> $requests Keyed by a caller-chosen name.
     * @return array<string, array<string|int, mixed>> Decoded response bodies, keyed the same way as $requests.
     *
     * @throws ConnectionException        Router unreachable / timed out.
     * @throws AuthenticationException    Credentials rejected.
     * @throws RouterOsException          RouterOS returned an error.
     * @throws MalformedResponseException Response could not be parsed.
     */
    public function getMany(array $requests): array;

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

    /**
     * Update a singleton "settings" menu that has no `.id` and no list
     * form (e.g. `/system/identity`, `/ip/dns`) — maps to RouterOS's own
     * "set" console command via REST's POST-to-command-path mechanism
     * (the same general mechanism post() above already uses, e.g.
     * `POST /interface/print`). This is a distinct method from post()
     * because it is a **write**: confirmed directly against a real
     * device (P21) that `PATCH /rest/system/identity` (no identifier —
     * the naive guess) fails with RouterOS's own `"missing or invalid
     * resource identifier"` (HTTP 400), while `POST
     * /rest/system/identity/set` succeeds. Like put()/patch()/delete(),
     * this must never use the read-path retry configuration — the same
     * "cannot safely retry an ambiguous failure after a write already
     * reached RouterOS" reasoning applies here exactly as it does to
     * every other write method.
     *
     * @param array<string, scalar> $body
     * @return array<string|int, mixed> Decoded response body.
     *
     * @throws ConnectionException        Router unreachable / timed out.
     * @throws AuthenticationException    Credentials rejected.
     * @throws RouterOsException          RouterOS returned an error.
     * @throws MalformedResponseException Response could not be parsed.
     */
    public function postWrite(string $path, array $body = []): array;
}
