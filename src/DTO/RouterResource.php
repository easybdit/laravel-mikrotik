<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * Normalized /system/resource data.
 *
 * Field set is limited to what MikroTik's own documentation confirms
 * (help.mikrotik.com "REST API" example response). Every property is
 * nullable: a field absent from a given RouterOS build/version simply
 * comes through as null rather than raising an error. $raw preserves
 * the complete original payload, including any field this DTO does not
 * explicitly expose yet.
 */
final class RouterResource
{
    public function __construct(
        public readonly ?string $architectureName,
        public readonly ?string $boardName,
        public readonly ?string $platform,
        public readonly ?string $cpu,
        public readonly ?int $cpuCount,
        public readonly ?int $cpuFrequency,
        public readonly ?int $cpuLoad,
        public readonly ?int $freeMemory,
        public readonly ?int $totalMemory,
        public readonly ?int $freeHddSpace,
        public readonly ?int $totalHddSpace,
        public readonly ?string $uptime,
        public readonly ?string $version,
        public readonly ?string $buildTime,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return [
            'architecture_name' => $this->architectureName,
            'board_name'        => $this->boardName,
            'platform'          => $this->platform,
            'cpu'               => $this->cpu,
            'cpu_count'         => $this->cpuCount,
            'cpu_frequency'     => $this->cpuFrequency,
            'cpu_load'          => $this->cpuLoad,
            'free_memory'       => $this->freeMemory,
            'total_memory'      => $this->totalMemory,
            'free_hdd_space'    => $this->freeHddSpace,
            'total_hdd_space'   => $this->totalHddSpace,
            'uptime'            => $this->uptime,
            'version'           => $this->version,
            'build_time'        => $this->buildTime,
        ];
    }
}
