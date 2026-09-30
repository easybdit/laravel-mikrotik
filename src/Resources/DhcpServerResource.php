<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\DhcpServer;
use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Easybdit\LaravelMikrotik\Exceptions\InvalidResourceException;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;
use Illuminate\Support\Collection;

/**
 * Typed, read+write access to RouterOS's `/ip/dhcp-server` menu (P16):
 * `list()`, `find()`, `add()`, `update()`, `remove()`, `enable()`,
 * `disable()` — same shape and verb mapping as P13's IpAddressResource,
 * confirmed directly against a real device (2 live DHCP servers
 * observed — see DhcpServer's docblock). `add()` requires `name`, the
 * server's own identifying field.
 */
final class DhcpServerResource
{
    private const PATH = '/ip/dhcp-server';

    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_\-.*]+$/';

    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    /** @param array<string, scalar> $filter @return Collection<int, DhcpServer> */
    public function list(array $filter = []): Collection
    {
        return collect($this->normalizer->normalizeDhcpServers($this->transport->get(self::PATH, $filter)));
    }

    public function find(string $id): DhcpServer
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeDhcpServer($this->transport->get(self::PATH . '/' . $id));
    }

    /** @param array<string, scalar> $attributes */
    public function add(array $attributes): DhcpServer
    {
        if (!array_key_exists('name', $attributes) || $attributes['name'] === '' || $attributes['name'] === null) {
            throw InvalidResourceException::missingRequiredField('DHCP server', 'name');
        }

        return $this->normalizer->normalizeDhcpServer(
            $this->transport->put(self::PATH, $this->wireAttributes($attributes))
        );
    }

    /** @param array<string, scalar> $attributes */
    public function update(string $id, array $attributes): DhcpServer
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeDhcpServer(
            $this->transport->patch(self::PATH . '/' . $id, $this->wireAttributes($attributes))
        );
    }

    public function remove(string $id): void
    {
        $id = self::assertValidIdentifier($id);

        $this->transport->delete(self::PATH . '/' . $id);
    }

    public function enable(string $id): DhcpServer
    {
        return $this->update($id, ['disabled' => false]);
    }

    public function disable(string $id): DhcpServer
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
