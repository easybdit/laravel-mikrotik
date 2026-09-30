<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of RouterOS's `/ip/firewall/filter` menu (P15). Field set
 * confirmed directly against a real device (RouterOS 7.10.2,
 * RB3011UiAS): `.id`, `chain`, `action`, `protocol`, `disabled`,
 * `comment` were all observed on live rules; `src-address`/`dst-address`/
 * `src-port`/`dst-port`/`in-interface`/`out-interface` are RouterOS's
 * own standard firewall-rule match fields (present on a rule only when
 * that rule actually sets them — absent, not guessed, when it doesn't).
 * Every property except $id is nullable, per this package's usual
 * normalization contract; $raw always keeps the complete row.
 *
 * **Confirmed against a real device**: RouterOS itself strips a `/32`
 * (single-host) suffix from `src-address`/`dst-address` on write —
 * `add(['src-address' => '203.0.113.99/32'])` is confirmed to read back
 * as `"203.0.113.99"` (no `/32`) on the very next `find()`. This is
 * RouterOS's own normalization, not something this package does or can
 * change — do not assume a CIDR suffix survives round-trip for a
 * single-host address.
 */
final class FirewallFilterRule
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $chain,
        public readonly ?string $action,
        public readonly ?string $protocol,
        public readonly ?string $srcAddress,
        public readonly ?string $dstAddress,
        public readonly ?string $srcPort,
        public readonly ?string $dstPort,
        public readonly ?string $inInterface,
        public readonly ?string $outInterface,
        public readonly ?bool $disabled,
        public readonly ?string $comment,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'chain'         => $this->chain,
            'action'        => $this->action,
            'protocol'      => $this->protocol,
            'src_address'   => $this->srcAddress,
            'dst_address'   => $this->dstAddress,
            'src_port'      => $this->srcPort,
            'dst_port'      => $this->dstPort,
            'in_interface'  => $this->inInterface,
            'out_interface' => $this->outInterface,
            'disabled'      => $this->disabled,
            'comment'       => $this->comment,
        ];
    }
}
