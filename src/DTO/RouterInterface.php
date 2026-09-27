<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of RouterOS's `/interface` menu.
 *
 * All fields below — including counters — come from a single verified
 * call: `GET /rest/interface`. An earlier version of this package made
 * a second call (`POST /interface/print` with `{"stats-detail":""}`)
 * on the assumption that counters needed a separate "stats" view, since
 * that's how RouterOS's own console splits them (`/interface print` vs
 * `/interface print stats-detail`). Real-device verification (RouterOS
 * 7.10.2, RB3011UiAS) showed this REST endpoint returns the full field
 * set — including rx/tx byte/packet/error/drop counters and link-up/
 * down timestamps — in one response, making the second call redundant.
 *
 * Every property is nullable: a field this package couldn't confirm, or
 * that RouterOS omitted for this interface, is simply null rather than
 * raising an error. $raw preserves the complete response row, including
 * any field this DTO does not explicitly expose (e.g. `l2mtu`, `slave`,
 * `default-name`, `fp-*` fast-path counters — all confirmed present on
 * a real device but out of this DTO's verified/typed field list).
 *
 * These are cumulative counters (bytes/packets since the interface last
 * reset — typically the last reboot), not a live/instantaneous rate.
 * See RouterConnection::interfaces() for why this package does not
 * offer a "live traffic" API here, and RouterConnection::interfaceRate()
 * for the one-shot rate reading it offers instead.
 */
final class RouterInterface
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $type,
        public readonly ?bool $running,
        public readonly ?bool $disabled,
        public readonly ?int $mtu,
        public readonly ?string $macAddress,
        public readonly ?string $comment,
        public readonly ?int $rxByte,
        public readonly ?int $txByte,
        public readonly ?int $rxPacket,
        public readonly ?int $txPacket,
        public readonly ?int $rxError,
        public readonly ?int $txError,
        public readonly ?int $rxDrop,
        public readonly ?int $txDrop,
        public readonly ?int $txQueueDrop,
        public readonly ?int $linkDowns,
        public readonly ?string $lastLinkDownTime,
        public readonly ?string $lastLinkUpTime,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return [
            'name'                => $this->name,
            'type'                => $this->type,
            'running'             => $this->running,
            'disabled'            => $this->disabled,
            'mtu'                 => $this->mtu,
            'mac_address'         => $this->macAddress,
            'comment'             => $this->comment,
            'rx_byte'             => $this->rxByte,
            'tx_byte'             => $this->txByte,
            'rx_packet'           => $this->rxPacket,
            'tx_packet'           => $this->txPacket,
            'rx_error'            => $this->rxError,
            'tx_error'            => $this->txError,
            'rx_drop'             => $this->rxDrop,
            'tx_drop'             => $this->txDrop,
            'tx_queue_drop'       => $this->txQueueDrop,
            'link_downs'          => $this->linkDowns,
            'last_link_down_time' => $this->lastLinkDownTime,
            'last_link_up_time'   => $this->lastLinkUpTime,
        ];
    }
}
