<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * A single one-shot traffic-rate reading for one interface, from RouterOS
 * `/interface monitor-traffic` run with the "once" argument.
 *
 * Verified directly against a real device (RouterOS 7.10.2, RB3011UiAS):
 *
 *   POST /rest/interface/monitor-traffic
 *   {"interface": "<name>", "once": ""}
 *
 * returns a JSON array containing one object with exactly the fields
 * this DTO exposes (all JSON-string-encoded numbers, per RouterOS REST's
 * usual convention). An invalid interface name returns HTTP 400 with
 * RouterOS's own validation message ("input does not match any value of
 * interface"), surfaced by this package as a RouterOsException — the
 * same exception type every other RouterOS-side error uses.
 *
 * This is a one-shot snapshot, not a live/streaming reading: RouterOS's
 * REST API has no supported way to keep a "monitor" command running, so
 * each call to RouterConnection::interfaceRate() performs one fresh
 * request and RouterOS computes the rate over its own brief internal
 * sampling window. Every property is nullable: a field this package
 * could not confirm, or that RouterOS omitted, is null. $raw preserves
 * the complete response.
 */
final class InterfaceRate
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?int $rxBitsPerSecond,
        public readonly ?int $txBitsPerSecond,
        public readonly ?int $rxPacketsPerSecond,
        public readonly ?int $txPacketsPerSecond,
        public readonly ?int $rxErrorsPerSecond,
        public readonly ?int $txErrorsPerSecond,
        public readonly ?int $rxDropsPerSecond,
        public readonly ?int $txDropsPerSecond,
        public readonly ?int $txQueueDropsPerSecond,
        public readonly ?int $fpRxBitsPerSecond,
        public readonly ?int $fpTxBitsPerSecond,
        public readonly ?int $fpRxPacketsPerSecond,
        public readonly ?int $fpTxPacketsPerSecond,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return [
            'name'                     => $this->name,
            'rx_bits_per_second'       => $this->rxBitsPerSecond,
            'tx_bits_per_second'       => $this->txBitsPerSecond,
            'rx_packets_per_second'    => $this->rxPacketsPerSecond,
            'tx_packets_per_second'    => $this->txPacketsPerSecond,
            'rx_errors_per_second'     => $this->rxErrorsPerSecond,
            'tx_errors_per_second'     => $this->txErrorsPerSecond,
            'rx_drops_per_second'      => $this->rxDropsPerSecond,
            'tx_drops_per_second'      => $this->txDropsPerSecond,
            'tx_queue_drops_per_second'=> $this->txQueueDropsPerSecond,
            'fp_rx_bits_per_second'    => $this->fpRxBitsPerSecond,
            'fp_tx_bits_per_second'    => $this->fpTxBitsPerSecond,
            'fp_rx_packets_per_second' => $this->fpRxPacketsPerSecond,
            'fp_tx_packets_per_second' => $this->fpTxPacketsPerSecond,
        ];
    }
}
