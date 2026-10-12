<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\SchedulerEntry;
use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Easybdit\LaravelMikrotik\Exceptions\InvalidResourceException;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;
use Illuminate\Support\Collection;

/**
 * Typed, read+write access to RouterOS's `/system/scheduler` menu
 * (P22): `list()`, `find()`, `add()`, `update()`, `remove()`,
 * `enable()`, `disable()`. `add()` requires `name`.
 *
 * A scheduler entry's `on-event` is a script/command RouterOS will run
 * with the entry owner's privileges when it fires. This package only
 * writes that text; it never runs an entry, and it does not inspect or
 * sandbox the script. Create entries `disabled=true` first if you want
 * to review them on the router before they can run.
 */
final class SchedulerResource
{
    private const PATH = '/system/scheduler';

    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_\-.*]+$/';

    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    /** @param array<string, scalar> $filter @return Collection<int, SchedulerEntry> */
    public function list(array $filter = []): Collection
    {
        return collect($this->normalizer->normalizeSchedulerEntries($this->transport->get(self::PATH, $filter)));
    }

    public function find(string $id): SchedulerEntry
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeSchedulerEntry($this->transport->get(self::PATH . '/' . $id));
    }

    /** @param array<string, scalar> $attributes e.g. ['name' => 'nightly', 'interval' => '1d', 'on-event' => '...', 'disabled' => true]. */
    public function add(array $attributes): SchedulerEntry
    {
        if (!array_key_exists('name', $attributes) || $attributes['name'] === '' || $attributes['name'] === null) {
            throw InvalidResourceException::missingRequiredField('scheduler entry', 'name');
        }

        return $this->normalizer->normalizeSchedulerEntry(
            $this->transport->put(self::PATH, $this->wireAttributes($attributes))
        );
    }

    /** @param array<string, scalar> $attributes */
    public function update(string $id, array $attributes): SchedulerEntry
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeSchedulerEntry(
            $this->transport->patch(self::PATH . '/' . $id, $this->wireAttributes($attributes))
        );
    }

    public function remove(string $id): void
    {
        $id = self::assertValidIdentifier($id);

        $this->transport->delete(self::PATH . '/' . $id);
    }

    public function enable(string $id): SchedulerEntry
    {
        return $this->update($id, ['disabled' => false]);
    }

    public function disable(string $id): SchedulerEntry
    {
        return $this->update($id, ['disabled' => true]);
    }

    /** @param array<string, scalar> $attributes @return array<string, scalar> */
    private function wireAttributes(array $attributes): array
    {
        \Easybdit\LaravelMikrotik\Support\AttributeValidator::assertSafe($attributes, self::class);

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
