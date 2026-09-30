<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of RouterOS's `/ip/pool` menu (P19). Field set confirmed
 * directly against a real device (RouterOS 7.10.2, RB3011UiAS): `.id`,
 * `name`, `ranges` — a pool has no `disabled`/`comment` field in
 * RouterOS's own model (none was present on any of the real pools
 * observed), which is why IpPoolResource has no enable()/disable() and
 * this DTO has no $comment — this package does not invent either.
 * $raw always keeps the complete row.
 */
final class IpPool
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $name,
        public readonly ?string $ranges,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id'     => $this->id,
            'name'   => $this->name,
            'ranges' => $this->ranges,
        ];
    }
}
