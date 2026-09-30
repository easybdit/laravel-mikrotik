<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;

/**
 * Namespace object for RouterOS's `/ppp/*` menus (P17). Implements
 * secrets() only (`/ppp/secret`) — PPP profiles/active-connections are
 * not implemented.
 */
final class PppResource
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    public function secrets(): PppSecretResource
    {
        return new PppSecretResource($this->transport, $this->normalizer);
    }
}
