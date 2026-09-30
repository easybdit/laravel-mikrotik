<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of RouterOS's `/ip/route` menu (P20). Field set confirmed
 * directly against a real device (RouterOS 7.10.2, RB3011UiAS): `.id`,
 * `dst-address`, `gateway`, `distance`, `scope`, `target-scope`,
 * `inactive`, `disabled` were all observed on live routes. `inactive`
 * (not "active") is RouterOS's own field name for whether a route is
 * currently in the active routing table — kept exactly as RouterOS
 * names it rather than inverted/renamed, to avoid inventing a
 * convention RouterOS itself doesn't use. Every property except $id is
 * nullable; $raw always keeps the complete row.
 */
final class IpRoute
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $dstAddress,
        public readonly ?string $gateway,
        public readonly ?int $distance,
        public readonly ?string $scope,
        public readonly ?string $targetScope,
        public readonly ?bool $inactive,
        public readonly ?bool $disabled,
        public readonly ?string $comment,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'dst_address'  => $this->dstAddress,
            'gateway'      => $this->gateway,
            'distance'     => $this->distance,
            'scope'        => $this->scope,
            'target_scope' => $this->targetScope,
            'inactive'     => $this->inactive,
            'disabled'     => $this->disabled,
            'comment'      => $this->comment,
        ];
    }
}
