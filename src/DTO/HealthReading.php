<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * The full set of hardware sensors a router reported at one point in
 * time. Devices differ in which sensors they expose (see HealthSensor),
 * so this is a keyed bag rather than a fixed-shape object — asking for
 * a sensor a device does not have returns null, it never throws.
 *
 * @implements IteratorAggregate<string, HealthSensor>
 */
final class HealthReading implements IteratorAggregate
{
    /** @param array<string, HealthSensor> $sensors */
    public function __construct(private readonly array $sensors, private readonly array $raw)
    {
    }

    public function get(string $name): ?HealthSensor
    {
        return $this->sensors[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->sensors);
    }

    /** @return array<string, HealthSensor> */
    public function all(): array
    {
        return $this->sensors;
    }

    public function isEmpty(): bool
    {
        return $this->sensors === [];
    }

    /** The untouched decoded response this reading was built from. */
    public function raw(): array
    {
        return $this->raw;
    }

    public function toArray(): array
    {
        return array_map(
            static fn (HealthSensor $sensor): array => $sensor->toArray(),
            $this->sensors
        );
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->sensors);
    }
}
