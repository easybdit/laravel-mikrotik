<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * An ordered list of log entries, in whatever order RouterOS returned
 * them. Log entries have no natural unique business key (unlike
 * interfaces/sensors), so this is a plain indexed list rather than a
 * keyed bag.
 *
 * @implements IteratorAggregate<int, LogEntry>
 */
final class LogCollection implements IteratorAggregate
{
    /** @param list<LogEntry> $entries */
    public function __construct(private readonly array $entries)
    {
    }

    /** @return list<LogEntry> */
    public function all(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function toArray(): array
    {
        return array_map(
            static fn (LogEntry $entry): array => $entry->toArray(),
            $this->entries
        );
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->entries);
    }
}
