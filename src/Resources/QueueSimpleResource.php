<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\SimpleQueue;
use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Easybdit\LaravelMikrotik\Exceptions\InvalidResourceException;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;
use Illuminate\Support\Collection;

/**
 * Typed, read+write access to RouterOS's `/queue/simple` menu (P18):
 * `list()`, `find()`, `add()`, `update()`, `remove()`, `enable()`,
 * `disable()` — confirmed directly against a real device (12 live
 * queues observed — see SimpleQueue's docblock). `add()` requires
 * `name`, the queue's own identifying field.
 */
final class QueueSimpleResource
{
    private const PATH = '/queue/simple';

    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_\-.*]+$/';

    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    /** @param array<string, scalar> $filter @return Collection<int, SimpleQueue> */
    public function list(array $filter = []): Collection
    {
        return collect($this->normalizer->normalizeSimpleQueues($this->transport->get(self::PATH, $filter)));
    }

    public function find(string $id): SimpleQueue
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeSimpleQueue($this->transport->get(self::PATH . '/' . $id));
    }

    /** @param array<string, scalar> $attributes e.g. ['name' => '...', 'target' => '192.168.1.0/24', 'max-limit' => '10M/10M']. */
    public function add(array $attributes): SimpleQueue
    {
        if (!array_key_exists('name', $attributes) || $attributes['name'] === '' || $attributes['name'] === null) {
            throw InvalidResourceException::missingRequiredField('simple queue', 'name');
        }

        return $this->normalizer->normalizeSimpleQueue(
            $this->transport->put(self::PATH, $this->wireAttributes($attributes))
        );
    }

    /** @param array<string, scalar> $attributes */
    public function update(string $id, array $attributes): SimpleQueue
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeSimpleQueue(
            $this->transport->patch(self::PATH . '/' . $id, $this->wireAttributes($attributes))
        );
    }

    public function remove(string $id): void
    {
        $id = self::assertValidIdentifier($id);

        $this->transport->delete(self::PATH . '/' . $id);
    }

    public function enable(string $id): SimpleQueue
    {
        return $this->update($id, ['disabled' => false]);
    }

    public function disable(string $id): SimpleQueue
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
