<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of RouterOS's `/ip/dhcp-server/lease` menu (P16). Field set
 * confirmed directly against a real device (RouterOS 7.10.2,
 * RB3011UiAS): `.id`, `address`, `mac-address`, `server`, `host-name`,
 * `status`, `dynamic`, `disabled` were all observed on live leases.
 * Every property except $id is nullable; $raw always keeps the
 * complete row.
 */
final class DhcpLease
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $address,
        public readonly ?string $macAddress,
        public readonly ?string $server,
        public readonly ?string $hostName,
        public readonly ?string $status,
        public readonly ?bool $dynamic,
        public readonly ?bool $disabled,
        public readonly ?string $comment,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'address'     => $this->address,
            'mac_address' => $this->macAddress,
            'server'      => $this->server,
            'host_name'   => $this->hostName,
            'status'      => $this->status,
            'dynamic'     => $this->dynamic,
            'disabled'    => $this->disabled,
            'comment'     => $this->comment,
        ];
    }
}
