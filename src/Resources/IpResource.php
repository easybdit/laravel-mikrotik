<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;

/**
 * Namespace object for RouterOS's `/ip/*` menus. P13 implemented
 * addresses() (`/ip/address`); P19/P20 add pools() (`/ip/pool`) and
 * routes() (`/ip/route`). Still a deliberately small slice, not the
 * whole `/ip` tree.
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

    public function pools(): IpPoolResource
    {
        return new IpPoolResource($this->transport, $this->normalizer);
    }

    public function routes(): IpRouteResource
    {
        return new IpRouteResource($this->transport, $this->normalizer);
    }
}
