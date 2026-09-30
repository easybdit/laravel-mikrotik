<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;

/**
 * Namespace object for RouterOS's `/ip/*` menus. P13 implements
 * addresses() only (`/ip/address`) — a deliberately small first slice,
 * not the whole `/ip` tree (routes, etc. are not implemented yet).
 */
final class IpResource
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    public function addresses(): IpAddressResource
    {
        return new IpAddressResource($this->transport, $this->normalizer);
    }
}
