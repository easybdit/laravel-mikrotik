<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\DhcpLease;
use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Easybdit\LaravelMikrotik\Exceptions\InvalidResourceException;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;
use Illuminate\Support\Collection;

/**
 * Typed, read+write access to RouterOS's `/ip/dhcp-server/lease` menu
 * (P16): `list()`, `find()`, `add()` (a static lease reservation),
 * `update()`, `remove()`, `enable()`, `disable()` — confirmed directly
 * against a real device (8 live leases observed — see DhcpLease's
 * docblock). `add()` requires `address`, mirroring IpAddressResource's
 * own `address` requirement.
 *
 * Most leases on a running network are *dynamic* (RouterOS-managed,
 * `dynamic: true`) — this package does not special-case dynamic vs.
 * static leases; RouterOS itself governs what a write against a
 * dynamic lease does or doesn't allow, and this class surfaces whatever
 * RouterOS returns exactly like every other write resource.
 */
final class DhcpLeaseResource
{
    private const PATH = '/ip/dhcp-server/lease';

    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_\-.*]+$/';

    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    /** @param array<string, scalar> $filter @return Collection<int, DhcpLease> */
    public function list(array $filter = []): Collection
    {
        return collect($this->normalizer->normalizeDhcpLeases($this->transport->get(self::PATH, $filter)));
    }

    public function find(string $id): DhcpLease
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeDhcpLease($this->transport->get(self::PATH . '/' . $id));
    }

    /** @param array<string, scalar> $attributes */
    public function add(array $attributes): DhcpLease
    {
        if (!array_key_exists('address', $attributes) || $attributes['address'] === '' || $attributes['address'] === null) {
            throw InvalidResourceException::missingRequiredField('DHCP lease', 'address');
        }

        return $this->normalizer->normalizeDhcpLease(
            $this->transport->put(self::PATH, $this->wireAttributes($attributes))
        );
    }

    /** @param array<string, scalar> $attributes */
    public function update(string $id, array $attributes): DhcpLease
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeDhcpLease(
            $this->transport->patch(self::PATH . '/' . $id, $this->wireAttributes($attributes))
        );
    }

    public function remove(string $id): void
    {
        $id = self::assertValidIdentifier($id);

        $this->transport->delete(self::PATH . '/' . $id);
    }

    public function enable(string $id): DhcpLease
    {
        return $this->update($id, ['disabled' => false]);
    }

    public function disable(string $id): DhcpLease
    {
        return $this->update($id, ['disabled' => true]);
    }

    /** @param array<string, scalar> $attributes @return array<string, scalar> */
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
