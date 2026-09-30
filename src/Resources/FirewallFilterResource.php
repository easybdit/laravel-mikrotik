<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\FirewallFilterRule;
use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Easybdit\LaravelMikrotik\Exceptions\InvalidResourceException;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;
use Illuminate\Support\Collection;

/**
 * Typed, read+write access to RouterOS's `/ip/firewall/filter` menu
 * (P15), following the same shape as P13's IpAddressResource: `list()`,
 * `find()`, `add()`, `update()`, `remove()`, `enable()`, `disable()`.
 *
 * HTTP verb mapping is the same documented convention every other
 * typed write resource in this package already uses (GET=print,
 * PUT=add, PATCH=set with the id in the path, DELETE=remove) —
 * RouterOS's firewall filter menu supports the standard console
 * add/set/remove/enable/disable commands, confirmed directly against a
 * real device (25 live rules observed, including `.id`/`chain`/
 * `action`/`disabled` — see FirewallFilterRule's docblock).
 *
 * `add()` requires `chain` — a firewall rule with no chain is not a
 * valid RouterOS rule at all (mirrors why IpAddressResource requires
 * `address`). RouterOS itself validates everything else.
 *
 * $id is validated against the same allow-list every other resource in
 * this package uses (own copy, same independence convention P13/P14
 * established).
 */
final class FirewallFilterResource
{
    private const PATH = '/ip/firewall/filter';

    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_\-.*]+$/';

    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    /**
     * @param array<string, scalar> $filter
     * @return Collection<int, FirewallFilterRule>
     */
    public function list(array $filter = []): Collection
    {
        return collect($this->normalizer->normalizeFirewallFilterRules($this->transport->get(self::PATH, $filter)));
    }

    /** @throws \Easybdit\LaravelMikrotik\Exceptions\RouterOsException */
    public function find(string $id): FirewallFilterRule
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeFirewallFilterRule($this->transport->get(self::PATH . '/' . $id));
    }

    /**
     * @param array<string, scalar> $attributes RouterOS field names, e.g. ['chain' => 'forward', 'action' => 'drop'].
     *
     * @throws InvalidResourceException $attributes is missing 'chain'.
     */
    public function add(array $attributes): FirewallFilterRule
    {
        if (!array_key_exists('chain', $attributes) || $attributes['chain'] === '' || $attributes['chain'] === null) {
            throw InvalidResourceException::missingRequiredField('firewall filter rule', 'chain');
        }

        return $this->normalizer->normalizeFirewallFilterRule(
            $this->transport->put(self::PATH, $this->wireAttributes($attributes))
        );
    }

    /** @param array<string, scalar> $attributes */
    public function update(string $id, array $attributes): FirewallFilterRule
    {
        $id = self::assertValidIdentifier($id);

        return $this->normalizer->normalizeFirewallFilterRule(
            $this->transport->patch(self::PATH . '/' . $id, $this->wireAttributes($attributes))
        );
    }

    public function remove(string $id): void
    {
        $id = self::assertValidIdentifier($id);

        $this->transport->delete(self::PATH . '/' . $id);
    }

    public function enable(string $id): FirewallFilterRule
    {
        return $this->update($id, ['disabled' => false]);
    }

    public function disable(string $id): FirewallFilterRule
    {
        return $this->update($id, ['disabled' => true]);
    }

    /**
     * @param array<string, scalar> $attributes
     * @return array<string, scalar>
     */
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
