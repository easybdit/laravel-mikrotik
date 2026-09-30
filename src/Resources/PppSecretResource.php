<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\PppSecret;
use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Easybdit\LaravelMikrotik\Exceptions\InvalidResourceException;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;
use Illuminate\Support\Collection;

/**
 * Typed, read+write access to RouterOS's `/ppp/secret` menu (P17):
 * `list()`, `find()`, `add()`, `update()`, `remove()`, `enable()`,
 * `disable()` — confirmed directly against a real device (1 live
 * PPPoE secret observed — see PppSecret's docblock). `add()` requires
 * `name`.
 *
 * **Security**: a secret's `password` is sensitive. This class never
 * logs, never includes in an exception, and never returns it in the
 * typed `PppSecret` DTO or its `->raw` — see PppSecret's docblock for
 * exactly what that means and why. Passing `password` *into* `add()`/
 * `update()` (to set/change it) is unaffected — that is your own data
 * going *to* RouterOS, not something this package is echoing back to
 * you; `wireAttributes()` sends it exactly like every other field, and
 * `RouterOsException`'s message/detail never echo request bodies (a
 * property confirmed for every other write resource in this package
 * already, since RouterOS's own error bodies never echo the request).
 */
final class PppSecretResource
{
    private const PATH = '/ppp/secret';

    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_\-.*]+$/';

    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    /** @param array<string, scalar> $filter @return Collection<int, PppSecret> */
    public function list(array $filter = []): Collection
    {
        return collect($this->normalizer->normalizePppSecrets($this->transport->get(self::PATH, $filter)));
    }

    public function find(string $id): PppSecret
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizePppSecret($this->transport->get(self::PATH . '/' . $id));
    }

    /** @param array<string, scalar> $attributes e.g. ['name' => '...', 'password' => '...', 'service' => 'pppoe']. */
    public function add(array $attributes): PppSecret
    {
        if (!array_key_exists('name', $attributes) || $attributes['name'] === '' || $attributes['name'] === null) {
            throw InvalidResourceException::missingRequiredField('PPP secret', 'name');
        }

        return $this->normalizer->normalizePppSecret(
            $this->transport->put(self::PATH, $this->wireAttributes($attributes))
        );
    }

    /** @param array<string, scalar> $attributes */
    public function update(string $id, array $attributes): PppSecret
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizePppSecret(
            $this->transport->patch(self::PATH . '/' . $id, $this->wireAttributes($attributes))
        );
    }

    public function remove(string $id): void
    {
        $id = self::assertValidIdentifier($id);

        $this->transport->delete(self::PATH . '/' . $id);
    }

    public function enable(string $id): PppSecret
    {
        return $this->update($id, ['disabled' => false]);
    }

    public function disable(string $id): PppSecret
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
