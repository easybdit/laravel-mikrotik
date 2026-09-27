<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of RouterOS's `/interface` menu, merged from two verified
 * views:
 *
 * - Identity/state fields (name, type, running, disabled, mtu,
 *   mac-address, comment) come from the base `GET /rest/interface`
 *   ("/interface print"), following the same field-naming convention
 *   confirmed across other RouterOS REST endpoints (e.g. /ip/address).
 * - Counter fields (rx/tx byte/packet, tx-queue-drop, link-downs,
 *   last-link-up/down-time) come from `POST /rest/interface/print` with
 *   body {"stats-detail":""} — confirmed directly from MikroTik's own
 *   documented "/interface print stats-detail" console output
 *   (help.mikrotik.com "Interface stats and monitor-traffic").
 *
 * Every property is nullable: a field this package couldn't confirm, or
 * that RouterOS omitted for this interface, is simply null rather than
 * raising an error. $raw preserves both source rows, merged, including
 * any field this DTO does not explicitly expose.
 *
 * These are cumulative counters (bytes/packets since the interface last
 * reset — typically the last reboot), not a live/instantaneous rate.
 * See RouterConnection::interfaces() for why this package does not
 * offer a "live traffic" API in this phase.
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
            'tx_queue_drop'       => $this->txQueueDrop,
            'link_downs'          => $this->linkDowns,
            'last_link_down_time' => $this->lastLinkDownTime,
            'last_link_up_time'   => $this->lastLinkUpTime,
        ];
    }
}
