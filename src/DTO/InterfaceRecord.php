<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of RouterOS's `/interface` menu, returned by the P14 typed
 * write resource (Resources\InterfaceResource). Distinct from
 * RouterInterface (RouterConnection::interfaces(), read-only, keyed by
 * name, no `.id`): this DTO carries `.id` because update()/enable()/
 * disable() need it, and intentionally exposes a smaller field set
 * (RouterInterface already owns the full counter set for read-only use
 * — duplicating that here would just be two DTOs drifting out of sync).
 * Every property except $id is nullable: a field RouterOS omitted, or
 * this DTO doesn't explicitly type, is left out rather than guessed at;
 * $raw always keeps the complete row.
 */
final class InterfaceRecord
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $name,
        public readonly ?string $type,
        public readonly ?bool $running,
        public readonly ?bool $disabled,
        public readonly ?string $comment,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'type'     => $this->type,
            'running'  => $this->running,
            'disabled' => $this->disabled,
            'comment'  => $this->comment,
        ];
    }
}
