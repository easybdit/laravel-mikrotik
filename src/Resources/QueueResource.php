<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;

/**
 * Namespace object for RouterOS's `/queue/*` menus (P18). Implements
 * simple() only (`/queue/simple`) — queue tree/type menus are not
 * implemented.
 */
final class QueueResource
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    public function simple(): QueueSimpleResource
    {
        return new QueueSimpleResource($this->transport, $this->normalizer);
    }
}
