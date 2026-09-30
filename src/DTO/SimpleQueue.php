<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of RouterOS's `/queue/simple` menu (P18). Field set confirmed
 * directly against a real device (RouterOS 7.10.2, RB3011UiAS): `.id`,
 * `name`, `target`, `max-limit`, `disabled`, `parent`, `priority` were
 * all observed on live queues. `maxLimit`/`priority` are kept as
 * RouterOS's own raw string (e.g. `"10M/10M"`, upload/download
 * separated by `/`) rather than parsed — this package does not invent a
 * split/parse convention RouterOS's own documentation doesn't specify.
 * Every property except $id is nullable; $raw always keeps the
 * complete row (including the many traffic-counter fields this DTO
 * does not type, e.g. `bytes`, `packets`, `queued-bytes`).
 */
final class SimpleQueue
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $name,
        public readonly ?string $target,
        public readonly ?string $maxLimit,
        public readonly ?string $parent,
        public readonly ?string $priority,
        public readonly ?bool $disabled,
        public readonly ?string $comment,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'target'    => $this->target,
            'max_limit' => $this->maxLimit,
            'parent'    => $this->parent,
            'priority'  => $this->priority,
            'disabled'  => $this->disabled,
            'comment'   => $this->comment,
        ];
    }
}
