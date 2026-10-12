<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of RouterOS's `/system/scheduler` menu (P22). Field set taken
 * from RouterOS's documented scheduler properties; not exercised
 * against a real device. $onEvent is the script/command RouterOS runs
 * when the entry fires -- this package only stores and returns that
 * text, it never triggers a run.
 */
final class SchedulerEntry
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $name,
        public readonly ?string $startDate,
        public readonly ?string $startTime,
        public readonly ?string $interval,
        public readonly ?string $onEvent,
        public readonly ?string $owner,
        public readonly ?int $runCount,
        public readonly ?string $nextRun,
        public readonly ?bool $disabled,
        public readonly ?string $comment,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'start_date' => $this->startDate,
            'start_time' => $this->startTime,
            'interval'   => $this->interval,
            'on_event'   => $this->onEvent,
            'owner'      => $this->owner,
            'run_count'  => $this->runCount,
            'next_run'   => $this->nextRun,
            'disabled'   => $this->disabled,
            'comment'    => $this->comment,
        ];
    }
}
