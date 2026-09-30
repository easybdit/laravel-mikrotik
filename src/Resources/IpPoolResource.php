<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\IpPool;
use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Easybdit\LaravelMikrotik\Exceptions\InvalidResourceException;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;
use Illuminate\Support\Collection;

/**
 * Typed, read+write access to RouterOS's `/ip/pool` menu (P19):
 * `list()`, `find()`, `add()`, `update()`, `remove()` — confirmed
 * directly against a real device (4 live pools observed — see IpPool's
 * docblock). **No `enable()`/`disable()`**: a pool has no `disabled`
 * property in RouterOS's own model (none was present on any real pool
 * observed) — this package does not force an operation onto a resource
 * that doesn't support it. `add()` requires `name`.
 */
final class IpPoolResource
{
    private const PATH = '/ip/pool';

    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_\-.*]+$/';

    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    /** @param array<string, scalar> $filter @return Collection<int, IpPool> */
    public function list(array $filter = []): Collection
    {
        return collect($this->normalizer->normalizeIpPools($this->transport->get(self::PATH, $filter)));
    }

    public function find(string $id): IpPool
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeIpPool($this->transport->get(self::PATH . '/' . $id));
    }

    /** @param array<string, scalar> $attributes e.g. ['name' => '...', 'ranges' => '192.168.1.10-192.168.1.20']. */
    public function add(array $attributes): IpPool
    {
        if (!array_key_exists('name', $attributes) || $attributes['name'] === '' || $attributes['name'] === null) {
            throw InvalidResourceException::missingRequiredField('IP pool', 'name');
        }

        return $this->normalizer->normalizeIpPool($this->transport->put(self::PATH, $attributes));
    }

    /** @param array<string, scalar> $attributes */
    public function update(string $id, array $attributes): IpPool
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeIpPool($this->transport->patch(self::PATH . '/' . $id, $attributes));
    }

    public function remove(string $id): void
    {
        $id = self::assertValidIdentifier($id);

        $this->transport->delete(self::PATH . '/' . $id);
    }

    private static function assertValidIdentifier(string $id): string
    {
        if ($id === '' || str_contains($id, '..') || preg_match(self::IDENTIFIER_PATTERN, $id) !== 1) {
            throw InvalidMenuPathException::invalidIdentifier($id);
        }

        return $id;
    }
}
