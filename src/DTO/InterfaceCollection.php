<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * The full set of interfaces a router reported. Keyed by RouterOS's own
 * interface name (unique per device) for convenient lookup — mirrors
 * HealthReading's shape for API consistency.
 *
 * @implements IteratorAggregate<string, RouterInterface>
 */
final class InterfaceCollection implements IteratorAggregate
{
    /** @param array<string, RouterInterface> $interfaces */
    public function __construct(private readonly array $interfaces)
    {
    }

    public function get(string $name): ?RouterInterface
    {
        return $this->interfaces[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->interfaces);
    }

    /** @return array<string, RouterInterface> */
    public function all(): array
    {
        return $this->interfaces;
    }

    public function isEmpty(): bool
    {
        return $this->interfaces === [];
    }

    public function count(): int
    {
        return count($this->interfaces);
    }

    public function toArray(): array
    {
        return array_map(
            static fn (RouterInterface $interface): array => $interface->toArray(),
            $this->interfaces
        );
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->interfaces);
    }
}
