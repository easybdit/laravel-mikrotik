<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of /system/health data.
 *
 * RouterOS exposes a different sensor set per board (verified against
 * MikroTik's own documentation: a CCR1072-1G-8S+ exposes power,
 * temperature, four fans and dual PSU voltage/current; other boards
 * expose only "voltage" and "temperature"; some legacy boards expose
 * numbered sensors like "voltage1".."voltage10"). This DTO therefore
 * represents exactly one arbitrary sensor rather than a fixed set of
 * named properties — never assume a given sensor name will be present.
 */
final class HealthSensor
{
    /**
     * @param string $name RouterOS's own sensor identifier, e.g. "cpu-temperature", "psu1-voltage", "fan1-speed".
     * @param string $rawValue The exact string RouterOS returned, preserved verbatim.
     * @param int|float|bool|string|null $value $rawValue normalized where it could be done safely; $rawValue unchanged (as a string) if normalization was not attempted/safe.
     * @param string|null $type RouterOS's reported unit/type where present (e.g. "C", "V", "A", "W", "RPM"), or null if RouterOS did not report one.
     * @param string|null $status One of RouterOS's known sensor status tokens (ok|fail|not-present|idle|no-input) when $rawValue matched one, otherwise null.
     * @param array<string, mixed> $raw The complete, untouched record this sensor was built from, for anything this DTO does not explicitly surface.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $rawValue,
        public readonly int|float|bool|string|null $value,
        public readonly ?string $type,
        public readonly ?string $status,
        public readonly array $raw,
    ) {
    }

    public function isNumeric(): bool
    {
        return is_int($this->value) || is_float($this->value);
    }

    public function toArray(): array
    {
        return [
            'name'      => $this->name,
            'raw_value' => $this->rawValue,
            'value'     => $this->value,
            'type'      => $this->type,
            'status'    => $this->status,
        ];
    }
}
