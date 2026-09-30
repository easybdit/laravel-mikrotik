<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * RouterOS's `/ip/dns` menu (P21) — a singleton settings object, not a
 * list (no `.id`, exactly one row always exists). Field set confirmed
 * directly against a real device (RouterOS 7.10.2, RB3011UiAS):
 * `servers`, `allow-remote-requests`, `cache-size`, `cache-max-ttl`
 * were all observed. `servers` is kept as RouterOS's own raw
 * comma-separated string rather than split into an array — this
 * package does not invent a parsing convention RouterOS's own
 * documentation doesn't specify. $raw always keeps the complete
 * response, including fields this DTO does not type (e.g. `cache-used`,
 * `doh-*`, `max-concurrent-queries`).
 */
final class DnsSettings
{
    public function __construct(
        public readonly ?string $servers,
        public readonly ?bool $allowRemoteRequests,
        public readonly ?string $cacheSize,
        public readonly ?string $cacheMaxTtl,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return [
            'servers'                => $this->servers,
            'allow_remote_requests'  => $this->allowRemoteRequests,
            'cache_size'             => $this->cacheSize,
            'cache_max_ttl'          => $this->cacheMaxTtl,
        ];
    }
}
