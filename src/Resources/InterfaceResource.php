<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\InterfaceRecord;
use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;
use Illuminate\Support\Collection;

/**
 * Typed, read+write access to RouterOS's `/interface` menu (P14) —
 * built on P12's write transport, following the same shape as P13's
 * IpAddressResource. Unlike Connection\Menu (read-only, raw/untyped),
 * this class is scoped to exactly one known, stable RouterOS menu and
 * returns typed DTO\InterfaceRecord objects.
 *
 * HTTP verb mapping (source: help.mikrotik.com "REST API"):
 *
 *   list()/find() -> GET   (print) -- confirmed with a documented
 *     /rest/interface/ether1 example.
 *   update()      -> PATCH (set), identifier in the path -- the
 *     documentation's only concrete PATCH body example is for a
 *     different menu (/ip/address, `{"comment": "test"}`), but PATCH's
 *     general contract ("fields and values of the properties to be
 *     updated") is documented as menu-agnostic, and this was confirmed
 *     directly against a real device for /interface (comment, disabled)
 *     during P14's real-device verification.
 *   enable()/disable() -> PATCH {"disabled": ...} -- same thin
 *     update() wrapper convention as P13's IpAddressResource; RouterOS
 *     REST has no documented dedicated enable/disable endpoint.
 *
 * No add()/remove(): the documentation does not confirm PUT/DELETE
 * support for `/interface`, and RouterOS itself does not generally
 * support creating/destroying a physical interface through this menu
 * (only specific virtual-interface submenus like /interface/vlan or
 * /interface/bridge do) — inventing either here would be guessing
 * behavior this package has no documented or observed basis for.
 *
 * $id (find/update/enable/disable) accepts either a RouterOS `.id`
 * (e.g. "*1") or the interface's own name (e.g. "ether1") — the
 * documentation's own /interface example addresses an item by name in
 * the URL path, and RouterOS REST generally accepts either form for a
 * menu whose rows have a unique, printable "name"-like identifying
 * field. Validated against the same allow-list Connection\Menu and
 * IpAddressResource already use (own copy — kept independent by
 * deliberate choice, same as P13).
 *
 * Every write goes through Transport::put()/patch()/delete() directly
 * (here: patch() only), never through the read-path retry
 * configuration and never through Menu.
 */
final class InterfaceResource
{
    private const PATH = '/interface';

    /** Same allow-list Connection\Menu and IpAddressResource use for an item identifier. */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_\-.*]+$/';

    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    /**
     * @param array<string, scalar> $filter Simple equality filters, the same `?field=value` form logs()/menu() already use.
     * @return Collection<int, InterfaceRecord>
     */
    public function list(array $filter = []): Collection
    {
        return collect($this->normalizer->normalizeInterfaceRecords($this->transport->get(self::PATH, $filter)));
    }

    /** @throws \Easybdit\LaravelMikrotik\Exceptions\RouterOsException RouterOS returned an error, including "not found". */
    public function find(string $id): InterfaceRecord
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeInterfaceRecord($this->transport->get(self::PATH . '/' . $id));
    }

    /** @param array<string, scalar> $attributes RouterOS field names to change, e.g. ['comment' => 'uplink']. */
    public function update(string $id, array $attributes): InterfaceRecord
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeInterfaceRecord(
            $this->transport->patch(self::PATH . '/' . $id, $this->wireAttributes($attributes))
        );
    }

    public function enable(string $id): InterfaceRecord
    {
        return $this->update($id, ['disabled' => false]);
    }

    public function disable(string $id): InterfaceRecord
    {
        return $this->update($id, ['disabled' => true]);
    }

    /**
     * Converts a native PHP bool to RouterOS REST's own wire convention
     * for a boolean-ish field ("true"/"false" strings) — the same
     * convention P13's IpAddressResource assumed and P13's real-device
     * verification confirmed for /ip/address; confirmed independently
     * for /interface during P14's own real-device verification.
     *
     * @param array<string, scalar> $attributes
     * @return array<string, scalar>
     */
    private function wireAttributes(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (is_bool($value)) {
                $attributes[$key] = $value ? 'true' : 'false';
            }
        }

        return $attributes;
    }

    private static function assertValidIdentifier(string $id): string
    {
        if ($id === '' || str_contains($id, '..') || preg_match(self::IDENTIFIER_PATTERN, $id) !== 1) {
            throw InvalidMenuPathException::invalidIdentifier($id);
        }

        return $id;
    }
}
