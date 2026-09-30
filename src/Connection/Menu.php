<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Connection;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Illuminate\Support\Collection;

/**
 * Generic, read-only access to any RouterOS REST menu this package does
 * not (and may never) expose a named/typed method for, e.g.
 * `$router->menu('ip/address')->get()`. This reuses Transport::get()
 * exactly the way every named method already does (GET -> RouterOS
 * "print", the same simple-equality query-string filtering logs()
 * already uses) — no new HTTP verb, no write operation, and it does not
 * change or replace resource()/health()/interfaces()/interfaceRate()/
 * logs() in any way.
 *
 * Results are plain Illuminate\Support\Collection instances of raw,
 * untyped associative arrays — exactly RouterOS's REST JSON as-is
 * (every value still a JSON-encoded string, per RouterOS REST's own
 * convention — see ResponseNormalizer's docblock). Unlike the named
 * DTOs, this class has no fixed schema to safely guess field types
 * from, so it never attempts to; normalize the fields you need
 * yourself, exactly as you would reading RouterOS's own REST response
 * directly.
 */
final class Menu
{
    /**
     * Menu path segments: letters, digits, "_", "-" only. Deliberately
     * excludes "/" duplication, ":", "?", "#", "@", whitespace, and
     * "..", which is what keeps a caller-supplied path from ever
     * escaping the /rest/ API root or injecting a scheme/host — the
     * validated path is used exactly as this package's other read
     * methods already build a path, never as a raw/absolute URL.
     */
    private const PATH_SEGMENT_PATTERN = '/^[A-Za-z0-9_\-]+$/';

    /**
     * RouterOS ".id" values (e.g. "*1") and named identifiers (e.g.
     * "ether1"). Deliberately excludes "/", ":", "?", "#", whitespace,
     * and "..".
     */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_\-.*]+$/';

    private readonly string $path;

    public function __construct(private readonly Transport $transport, string $path)
    {
        $this->path = self::assertValidPath($path);
    }

    /**
     * RouterOS "print" over this menu. $filter is sent as simple
     * equality query-string parameters — the same documented `?field=value`
     * form RouterConnection::logs() already uses, and the same caveat
     * applies: RouterOS's console-only `~` (contains/regex) filter
     * operator is not supported here.
     *
     * @param array<string, scalar> $filter
     * @return Collection<int, array<string, mixed>>
     */
    public function get(array $filter = []): Collection
    {
        return collect($this->asRowList($this->transport->get($this->path, $filter)));
    }

    /**
     * A single item by its RouterOS ".id" (e.g. "*1") or, where a menu
     * supports it, its name (e.g. "ether1") — RouterOS REST's documented
     * `GET /rest/<menu>/<id>` form.
     *
     * A not-found condition is NOT converted to null here: RouterOS's
     * exact documented error shape for a GET (as opposed to a DELETE,
     * where it is confirmed to be `{"error":404,"message":"Not Found"}`)
     * targeting an unknown identifier was not independently confirmed
     * against a live device (see the package README). Rather than
     * guess which failure this method should silently swallow, a
     * failure of any kind surfaces exactly the way every other
     * RouterOS-side error already does — as a RouterOsException (or
     * AuthenticationException/ConnectionException, as appropriate).
     *
     * @return array<string, mixed>
     *
     * @throws \Easybdit\LaravelMikrotik\Exceptions\RouterOsException RouterOS returned an error, including "not found".
     */
    public function find(string $id): array
    {
        // Not URL-encoded: RouterOS's own documented example
        // (help.mikrotik.com "REST API") shows a literal, unencoded
        // ".id" in the path — e.g. ".../ip/address/*1", not
        // ".../ip/address/%2A1". $id was already validated above
        // against a strict allow-list (letters/digits/"_"/"-"/"."/"*"
        // only), which is what makes embedding it verbatim safe here,
        // not URL-encoding.
        $id = self::assertValidIdentifier($id);

        return $this->asSingleRecord($this->transport->get($this->path . '/' . $id));
    }

    /** @return list<array<string, mixed>> */
    private function asRowList(array $raw): array
    {
        if (array_is_list($raw)) {
            return array_values(array_filter($raw, 'is_array'));
        }

        return $raw === [] ? [] : [$raw];
    }

    /** @return array<string, mixed> */
    private function asSingleRecord(array $raw): array
    {
        if (array_is_list($raw)) {
            $first = $raw[0] ?? [];

            return is_array($first) ? $first : [];
        }

        return $raw;
    }

    private static function assertValidPath(string $path): string
    {
        if ($path === '' || !self::allSegmentsMatch($path, self::PATH_SEGMENT_PATTERN)) {
            throw InvalidMenuPathException::invalidPath($path);
        }

        return $path;
    }

    private static function assertValidIdentifier(string $id): string
    {
        // "." is otherwise a legitimate identifier character (RouterOS
        // dotted names), so the charset check alone does not reject ".."
        // -- checked separately here rather than dropping "." from the
        // allowed set entirely.
        if ($id === '' || str_contains($id, '..') || preg_match(self::IDENTIFIER_PATTERN, $id) !== 1) {
            throw InvalidMenuPathException::invalidIdentifier($id);
        }

        return $id;
    }

    private static function allSegmentsMatch(string $path, string $pattern): bool
    {
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || preg_match($pattern, $segment) !== 1) {
                return false;
            }
        }

        return true;
    }
}
