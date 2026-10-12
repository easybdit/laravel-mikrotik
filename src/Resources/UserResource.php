<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\RouterUser;
use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Easybdit\LaravelMikrotik\Exceptions\InvalidResourceException;
use Easybdit\LaravelMikrotik\Exceptions\UnsafeOperationException;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;
use Illuminate\Support\Collection;

/**
 * Typed, read+write access to RouterOS's `/user` menu (P22):
 * `list()`, `find()`, `add()`, `update()`, `remove()`, `enable()`,
 * `disable()`. `add()` requires `name` and a non-empty `password`
 * (an account without a password is refused rather than created).
 *
 * **Security**: credential fields are never returned -- see RouterUser
 * and Support\SecretRedactor. Passing `password` *into* `add()`/
 * `update()` is unaffected (it goes to RouterOS, never echoed back).
 * This package cannot know which account your connection itself
 * authenticates as: removing or disabling that account, or the last
 * full-access one, can lock you out of the router. That is your
 * responsibility; test against a scratch account first.
 */
final class UserResource
{
    private const PATH = '/user';

    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_\-.*]+$/';

    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
        private readonly ?string $activeUsername = null,
    ) {
    }

    /** @param array<string, scalar> $filter @return Collection<int, RouterUser> */
    public function list(array $filter = []): Collection
    {
        return collect($this->normalizer->normalizeRouterUsers($this->transport->get(self::PATH, $filter)));
    }

    public function find(string $id): RouterUser
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeRouterUser($this->transport->get(self::PATH . '/' . $id));
    }

    /** @param array<string, scalar> $attributes e.g. ['name' => '...', 'password' => '...', 'group' => 'read']. */
    public function add(array $attributes): RouterUser
    {
        if (!array_key_exists('name', $attributes) || $attributes['name'] === '' || $attributes['name'] === null) {
            throw InvalidResourceException::missingRequiredField('router user', 'name');
        }

        if (!array_key_exists('password', $attributes) || $attributes['password'] === '' || $attributes['password'] === null) {
            throw InvalidResourceException::missingRequiredField('router user', 'password');
        }

        return $this->normalizer->normalizeRouterUser(
            $this->transport->put(self::PATH, $this->wireAttributes($attributes))
        );
    }

    /**
     * P25: when the connection's own username is known, an update that
     * disables or renames the account this connection logs in as is
     * refused unless $allowActiveUser is true (it would lock this very
     * connection out). Group/address changes are not guarded.
     *
     * @param array<string, scalar> $attributes
     * @throws UnsafeOperationException
     */
    public function update(string $id, array $attributes, bool $allowActiveUser = false): RouterUser
    {
        $id = self::assertValidIdentifier($id);
        \Easybdit\LaravelMikrotik\Support\AttributeValidator::assertSafe($attributes, self::class);

        $disabled = $attributes['disabled'] ?? false;
        $disables = $disabled === true || in_array(strtolower((string) $disabled), ['true', 'yes'], true);

        if ($disables || array_key_exists('name', $attributes)) {
            $this->guardActiveUser($id, 'modify', $allowActiveUser);
        }

        return $this->normalizer->normalizeRouterUser(
            $this->transport->patch(self::PATH . '/' . $id, $this->wireAttributes($attributes))
        );
    }

    /** @throws UnsafeOperationException $id is the account this connection logs in as and $allowActiveUser is false. */
    public function remove(string $id, bool $allowActiveUser = false): void
    {
        $id = self::assertValidIdentifier($id);

        $this->guardActiveUser($id, 'delete', $allowActiveUser);

        $this->transport->delete(self::PATH . '/' . $id);
    }

    public function enable(string $id): RouterUser
    {
        return $this->update($id, ['disabled' => false]);
    }

    /** @throws UnsafeOperationException $id is the account this connection logs in as and $allowActiveUser is false. */
    public function disable(string $id, bool $allowActiveUser = false): RouterUser
    {
        $this->guardActiveUser(self::assertValidIdentifier($id), 'disable', $allowActiveUser);

        return $this->update($id, ['disabled' => true], allowActiveUser: true);
    }

    private function guardActiveUser(string $id, string $action, bool $allow): void
    {
        if ($allow || $this->activeUsername === null) {
            return;
        }

        if ($this->find($id)->name === $this->activeUsername) {
            throw UnsafeOperationException::activeUser($action, $this->activeUsername);
        }
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
