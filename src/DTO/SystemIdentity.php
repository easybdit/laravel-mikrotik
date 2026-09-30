<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * RouterOS's `/system/identity` menu (P21) — a singleton settings
 * object, not a list (no `.id`). Confirmed directly against a real
 * device (RouterOS 7.10.2, RB3011UiAS) that this menu has exactly one
 * field: `name` (the router's own hostname/identity string). $raw
 * always keeps the complete response.
 */
final class SystemIdentity
{
    public function __construct(
        public readonly ?string $name,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return ['name' => $this->name];
    }
}
