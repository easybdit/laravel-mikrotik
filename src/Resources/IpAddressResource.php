<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\IpAddress;
use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Easybdit\LaravelMikrotik\Exceptions\InvalidResourceException;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;
use Illuminate\Support\Collection;

/**
 * Typed, read+write access to RouterOS's `/ip/address` menu (P13) — the
 * first resource built on P12's write transport. Unlike Connection\Menu
 * (deliberately read-only, raw/untyped, for menus this package hasn't
 * named), this class is scoped to exactly one known, stable RouterOS
 * menu and returns typed DTO\IpAddress objects.
 *
 * HTTP verb mapping (source: help.mikrotik.com "REST API", confirmed
 * with a documented /ip/address example for each):
 *
 *   list()/find()  -> GET    (print)
 *   add()          -> PUT    (add) -- no identifier in the path
 *   update()       -> PATCH  (set) -- identifier in the path
 *   remove()       -> DELETE (remove) -- identifier in the path, no body
 *   enable()/disable() -> PATCH {"disabled": ...} -- RouterOS REST has
 *     no documented dedicated enable/disable endpoint, so these are
 *     thin convenience wrappers around update() with the "disabled"
 *     field, which the documentation confirms PATCH accepts for any
 *     property.
 *
 * $id (find/update/remove/enable/disable) is validated the same way
 * Connection\Menu already validates one -- letters/digits/"_"/"-"/"."/
 * "*" only, no "..", no "/" -- so a caller can never escape the
 * /rest/ API root through this class either.
 *
 * Every write goes through Transport::put()/patch()/delete() directly,
 * never through the read-path retry configuration (see RestTransport's
 * docblock) and never through Menu.
 */
final class IpAddressResource
{
    private const PATH = '/ip/address';

    /** Same allow-list Connection\Menu uses for an item identifier. */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_\-.*]+$/';

    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    /**
     * @param array<string, scalar> $filter Simple equality filters, the same `?field=value` form logs()/menu() already use.
     * @return Collection<int, IpAddress>
     */
    public function list(array $filter = []): Collection
    {
        return collect($this->normalizer->normalizeIpAddresses($this->transport->get(self::PATH, $filter)));
    }

    /** @throws \Easybdit\LaravelMikrotik\Exceptions\RouterOsException RouterOS returned an error, including "not found". */
    public function find(string $id): IpAddress
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeIpAddress($this->transport->get(self::PATH . '/' . $id));
    }

    /**
     * @param array<string, scalar> $attributes RouterOS field names, e.g. ['address' => '192.168.88.1/24', 'interface' => 'bridge1'].
     *
     * @throws InvalidResourceException $attributes is missing 'address'.
     */
    public function add(array $attributes): IpAddress
    {
        if (!array_key_exists('address', $attributes) || $attributes['address'] === '' || $attributes['address'] === null) {
            throw InvalidResourceException::missingRequiredField('ip address', 'address');
        }

        return $this->normalizer->normalizeIpAddress(
            $this->transport->put(self::PATH, $this->wireAttributes($attributes))
        );
    }

    /** @param array<string, scalar> $attributes RouterOS field names to change. */
    public function update(string $id, array $attributes): IpAddress
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeIpAddress(
            $this->transport->patch(self::PATH . '/' . $id, $this->wireAttributes($attributes))
        );
    }

    public function remove(string $id): void
    {
        $id = self::assertValidIdentifier($id);

        $this->transport->delete(self::PATH . '/' . $id);
    }

    public function enable(string $id): IpAddress
    {
        return $this->update($id, ['disabled' => false]);
    }

    public function disable(string $id): IpAddress
    {
        return $this->update($id, ['disabled' => true]);
    }

    /**
     * Converts a native PHP bool to RouterOS REST's own wire convention
     * for a boolean-ish field -- assumed consistent with the "true"/
     * "false" string tokens GET responses are documented and already
     * confirmed to use (see ResponseNormalizer's docblock), since no
     * write-specific example was available to confirm this independently.
     * Flagged for real-device confirmation (see the package README).
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
