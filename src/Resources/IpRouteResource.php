<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\IpRoute;
use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Easybdit\LaravelMikrotik\Exceptions\InvalidResourceException;
use Easybdit\LaravelMikrotik\Exceptions\UnsafeOperationException;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;
use Illuminate\Support\Collection;

/**
 * Typed, read+write access to RouterOS's `/ip/route` menu (P20):
 * `list()`, `find()`, `add()`, `update()`, `remove()`, `enable()`,
 * `disable()` — confirmed directly against a real device (11 live
 * routes observed, including the router's own default route — see
 * IpRoute's docblock). `add()` requires `dst-address`, mirroring
 * IpAddressResource's own `address` requirement.
 *
 * **This is the package's highest-risk write resource**: an incorrect
 * write against `/ip/route` can affect a router's default gateway or
 * reachability. This package validates only what it can safely
 * guarantee (identifier safety, `dst-address` presence) before sending
 * a request — it does not, and cannot, know which route on your router
 * is "the important one." See the README's real-device testing guide
 * for how this package's own real-device testing handled this risk
 * (an isolated test route only, default/production routes never
 * touched).
 */
final class IpRouteResource
{
    private const PATH = '/ip/route';

    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_\-.*]+$/';

    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    /** @param array<string, scalar> $filter @return Collection<int, IpRoute> */
    public function list(array $filter = []): Collection
    {
        return collect($this->normalizer->normalizeIpRoutes($this->transport->get(self::PATH, $filter)));
    }

    public function find(string $id): IpRoute
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeIpRoute($this->transport->get(self::PATH . '/' . $id));
    }

    /** @param array<string, scalar> $attributes e.g. ['dst-address' => '203.0.113.0/24', 'gateway' => '...']. */
    public function add(array $attributes): IpRoute
    {
        if (!array_key_exists('dst-address', $attributes) || $attributes['dst-address'] === '' || $attributes['dst-address'] === null) {
            throw InvalidResourceException::missingRequiredField('IP route', 'dst-address');
        }

        return $this->normalizer->normalizeIpRoute(
            $this->transport->put(self::PATH, $this->wireAttributes($attributes))
        );
    }

    /**
     * P25: an update that disables a route or changes its dst-address is
     * refused for a default route (0.0.0.0/0) unless $allowDefaultRoute
     * is true. Other updates (e.g. changing the gateway or comment) are
     * unguarded and cost no extra read.
     *
     * @param array<string, scalar> $attributes
     * @throws UnsafeOperationException
     */
    public function update(string $id, array $attributes, bool $allowDefaultRoute = false): IpRoute
    {
        $id = self::assertValidIdentifier($id);
        \Easybdit\LaravelMikrotik\Support\AttributeValidator::assertSafe($attributes, self::class);

        if (!$allowDefaultRoute && (self::isTruthy($attributes['disabled'] ?? false) || array_key_exists('dst-address', $attributes))) {
            $this->guardDefaultRoute($id, 'modify', false);
        }

        return $this->normalizer->normalizeIpRoute(
            $this->transport->patch(self::PATH . '/' . $id, $this->wireAttributes($attributes))
        );
    }

    /**
     * P25 safeguard: the route is looked up first (one extra read; the
     * write itself is still never retried) and a default route
     * (dst-address 0.0.0.0/0) is refused unless $allowDefaultRoute is true.
     *
     * @throws UnsafeOperationException The route is a default route and $allowDefaultRoute is false.
     */
    public function remove(string $id, bool $allowDefaultRoute = false): void
    {
        $id = self::assertValidIdentifier($id);

        $this->guardDefaultRoute($id, 'delete', $allowDefaultRoute);

        $this->transport->delete(self::PATH . '/' . $id);
    }

    public function enable(string $id): IpRoute
    {
        return $this->update($id, ['disabled' => false]);
    }

    /** @throws UnsafeOperationException The route is a default route and $allowDefaultRoute is false. */
    public function disable(string $id, bool $allowDefaultRoute = false): IpRoute
    {
        $this->guardDefaultRoute(self::assertValidIdentifier($id), 'disable', $allowDefaultRoute);

        return $this->update($id, ['disabled' => true], allowDefaultRoute: true);
    }

    private static function isTruthy(mixed $value): bool
    {
        return $value === true || (is_string($value) && in_array(strtolower($value), ['true', 'yes'], true));
    }

    private function guardDefaultRoute(string $id, string $action, bool $allowDefaultRoute): void
    {
        if ($allowDefaultRoute) {
            return;
        }

        if ($this->find($id)->dstAddress === '0.0.0.0/0') {
            throw UnsafeOperationException::defaultRoute($action, $id);
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
