<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;

/**
 * Namespace object for RouterOS's `/ip/dhcp-server*` menus (P16):
 * servers() (`/ip/dhcp-server`) and leases() (`/ip/dhcp-server/lease`).
 * DHCP relay/client/option menus are not implemented.
 */
final class DhcpResource
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    public function servers(): DhcpServerResource
    {
        return new DhcpServerResource($this->transport, $this->normalizer);
    }

    public function leases(): DhcpLeaseResource
    {
        return new DhcpLeaseResource($this->transport, $this->normalizer);
    }
}
