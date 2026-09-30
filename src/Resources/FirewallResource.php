<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;

/**
 * Namespace object for RouterOS's `/ip/firewall/*` menus (P15).
 * Implements filter() only (`/ip/firewall/filter`) — a deliberately
 * small first slice, the same "one menu at a time" approach P13 took
 * for `/ip/*` (NAT, mangle, address-list, etc. are not implemented).
 */
final class FirewallResource
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    public function filter(): FirewallFilterResource
    {
        return new FirewallFilterResource($this->transport, $this->normalizer);
    }
}
