<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of RouterOS's `/ip/address` menu (P13). Field set matches
 * MikroTik's own documented PUT/PATCH response example
 * (help.mikrotik.com "REST API"): `{".id":"*A","actual-interface":
 * "dummy","address":"192.168.111.111/32",...}`. Every property is
 * nullable except $id: a field RouterOS omitted, or one this DTO
 * doesn't explicitly type, is simply absent/kept in $raw rather than
 * raising an error — the same philosophy every other DTO in this
 * package already follows.
 */
final class IpAddress
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $address,
        public readonly ?string $network,
        public readonly ?string $interface,
        public readonly ?string $actualInterface,
        public readonly ?bool $disabled,
        public readonly ?bool $dynamic,
        public readonly ?bool $invalid,
        public readonly ?string $comment,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'address'          => $this->address,
            'network'          => $this->network,
            'interface'        => $this->interface,
            'actual_interface' => $this->actualInterface,
            'disabled'         => $this->disabled,
            'dynamic'          => $this->dynamic,
            'invalid'          => $this->invalid,
            'comment'          => $this->comment,
        ];
    }
}
