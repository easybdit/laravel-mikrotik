<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of RouterOS's `/ip/dhcp-server` menu (P16). Field set
 * confirmed directly against a real device (RouterOS 7.10.2,
 * RB3011UiAS): `.id`, `name`, `interface`, `address-pool`,
 * `lease-time`, `disabled` were all observed on live servers. Every
 * property except $id is nullable; $raw always keeps the complete row.
 */
final class DhcpServer
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $name,
        public readonly ?string $interface,
        public readonly ?string $addressPool,
        public readonly ?string $leaseTime,
        public readonly ?bool $disabled,
        public readonly ?string $comment,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'interface'    => $this->interface,
            'address_pool' => $this->addressPool,
            'lease_time'   => $this->leaseTime,
            'disabled'     => $this->disabled,
            'comment'      => $this->comment,
        ];
    }
}
