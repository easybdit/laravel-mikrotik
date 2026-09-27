<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Support;

use Easybdit\LaravelMikrotik\DTO\HealthReading;
use Easybdit\LaravelMikrotik\DTO\HealthSensor;
use Easybdit\LaravelMikrotik\DTO\RouterResource;

/**
 * Converts raw, already-JSON-decoded RouterOS REST payloads into this
 * package's DTOs.
 *
 * RouterOS's REST API returns every value as a JSON string regardless
 * of its real type (confirmed: help.mikrotik.com "REST API" — JSON
 * format notes). This class casts only fields it *knows* the real type
 * of; it deliberately never applies a blanket is_numeric()-style cast
 * across an entire payload, because a field that merely looks numeric
 * is not necessarily meant to be treated as one (e.g. a MAC address
 * segment, a version string). A value that cannot be safely normalized
 * is left as the original string rather than guessed at or dropped.
 */
final class ResponseNormalizer
{
    /**
     * RouterOS's documented sensor status tokens (source: RouterOS CLI
     * reference for system/health — "value" is either numeric or one of
     * these enum tokens for presence/state-style sensors).
     */
    private const HEALTH_STATUS_TOKENS = ['ok', 'fail', 'not-present', 'idle', 'no-input'];

    public function normalizeResource(array $raw): RouterResource
    {
        // MikroTik's documented example response for GET /rest/system/resource
        // is a JSON array containing a single object. Some deployments may
        // instead receive that object directly; both are accepted.
        $record = $this->firstRecord($raw);

        // Each numeric field below is cast explicitly and individually
        // (rather than via a blanket is_numeric() sweep over the whole
        // payload) — each one is a field MikroTik's documented example
        // response confirms is numeric in RouterOS's own model, even
        // though REST always encodes it as a JSON string.
        return new RouterResource(
            architectureName: $this->stringOrNull($record, 'architecture-name'),
            boardName: $this->stringOrNull($record, 'board-name'),
            platform: $this->stringOrNull($record, 'platform'),
            cpu: $this->stringOrNull($record, 'cpu'),
            cpuCount: $this->intOrNull($record, 'cpu-count'),
            cpuFrequency: $this->intOrNull($record, 'cpu-frequency'),
            cpuLoad: $this->intOrNull($record, 'cpu-load'),
            freeMemory: $this->intOrNull($record, 'free-memory'),
            totalMemory: $this->intOrNull($record, 'total-memory'),
            freeHddSpace: $this->intOrNull($record, 'free-hdd-space'),
            totalHddSpace: $this->intOrNull($record, 'total-hdd-space'),
            uptime: $this->stringOrNull($record, 'uptime'),
            version: $this->stringOrNull($record, 'version'),
            buildTime: $this->stringOrNull($record, 'build-time'),
            raw: $record,
        );
    }

    /**
     * Builds a HealthReading from a raw /system/health payload.
     *
     * NOTE ON VERIFICATION: MikroTik's documentation confirms the CLI
     * table columns for `/system/health/print` are NAME, VALUE, TYPE,
     * and RouterOS REST is documented to mirror console "print" output
     * as one JSON object per row using the row's own column names as
     * object keys (this is directly confirmed for other endpoints, e.g.
     * /rest/ip/address, in MikroTik's REST API documentation). This
     * normalizer therefore treats the *primary, expected* shape as a
     * JSON array of records like {"name": "...", "value": "...", "type": "..."}.
     * A live device was not available to capture and confirm this exact
     * response body for /system/health specifically, so a defensive
     * fallback also accepts a single flat object (older-style
     * `sensor-name => value` pairs, as seen in RouterOS's legacy CLI/wiki
     * output) without raising an exception either way. Re-verify this
     * against a real device/RouterOS version during integration testing.
     */
    public function normalizeHealth(array $raw): HealthReading
    {
        $rows = $this->healthRows($raw);
        $sensors = [];

        foreach ($rows as $row) {
            $sensor = $this->buildHealthSensor($row);
            if ($sensor !== null) {
                $sensors[$sensor->name] = $sensor;
            }
        }

        return new HealthReading($sensors, $raw);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function healthRows(array $raw): array
    {
        // Row-style: a list of records, each with at least a "name".
        if ($this->isList($raw) && $this->allRowsHaveName($raw)) {
            return $raw;
        }

        // A single row-style record (not wrapped in a list).
        if (!$this->isList($raw) && array_key_exists('name', $raw)) {
            return [$raw];
        }

        // Fallback: a flat "sensor-name => raw value" object (legacy
        // CLI-style shape). Unknown/unexpected keys are still preserved,
        // never dropped or exception-raised, per this package's
        // normalization contract.
        if (!$this->isList($raw)) {
            $rows = [];
            foreach ($raw as $name => $value) {
                if (is_string($name)) {
                    $rows[] = ['name' => $name, 'value' => $value];
                }
            }

            return $rows;
        }

        return [];
    }

    private function buildHealthSensor(array $row): ?HealthSensor
    {
        $name = $row['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }

        $rawValue = $row['value'] ?? null;
        $rawValueString = is_scalar($rawValue) ? (string) $rawValue : '';
        $type = isset($row['type']) && $row['type'] !== '' ? (string) $row['type'] : null;

        [$value, $status] = $this->normalizeSensorValue($rawValueString);

        return new HealthSensor(
            name: $name,
            rawValue: $rawValueString,
            value: $value,
            type: $type,
            status: $status,
            raw: $row,
        );
    }

    /**
     * @return array{0: int|float|bool|string|null, 1: string|null}
     */
    private function normalizeSensorValue(string $rawValue): array
    {
        $trimmed = strtolower(trim($rawValue));

        if (in_array($trimmed, self::HEALTH_STATUS_TOKENS, true)) {
            return [$trimmed, $trimmed];
        }

        if ($trimmed === '') {
            return [null, null];
        }

        // Legacy CLI-style values may carry a trailing unit, e.g. "46C",
        // "62.9W", "12.1V" — strip a trailing unit before testing
        // numeric-ness, but only for suffixes RouterOS's own health
        // documentation uses (C, V, A, W, RPM); anything else is left
        // untouched rather than guessed at.
        $numericPart = preg_replace('/(C|V|A|W|RPM)$/i', '', $rawValue) ?? $rawValue;
        $numericPart = trim($numericPart);

        if (is_numeric($numericPart)) {
            return [str_contains($numericPart, '.') ? (float) $numericPart : (int) $numericPart, null];
        }

        // Not a recognised status token and not safely numeric — preserve
        // the original string rather than silently corrupting it.
        return [$rawValue, null];
    }

    private function firstRecord(array $raw): array
    {
        if ($this->isList($raw)) {
            $first = $raw[0] ?? [];

            return is_array($first) ? $first : [];
        }

        return $raw;
    }

    private function allRowsHaveName(array $rows): bool
    {
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists('name', $row)) {
                return false;
            }
        }

        return $rows !== [];
    }

    private function isList(array $value): bool
    {
        return array_is_list($value);
    }

    private function stringOrNull(array $record, string $key): ?string
    {
        return isset($record[$key]) && is_scalar($record[$key]) ? (string) $record[$key] : null;
    }

    private function intOrNull(array $record, string $key): ?int
    {
        if (!isset($record[$key]) || !is_scalar($record[$key])) {
            return null;
        }

        $value = (string) $record[$key];

        return is_numeric($value) ? (int) $value : null;
    }
}
