<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Support;

use Easybdit\LaravelMikrotik\DTO\HealthReading;
use Easybdit\LaravelMikrotik\DTO\HealthSensor;
use Easybdit\LaravelMikrotik\DTO\InterfaceCollection;
use Easybdit\LaravelMikrotik\DTO\InterfaceRate;
use Easybdit\LaravelMikrotik\DTO\IpAddress;
use Easybdit\LaravelMikrotik\DTO\LogCollection;
use Easybdit\LaravelMikrotik\DTO\LogEntry;
use Easybdit\LaravelMikrotik\DTO\RouterInterface;
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
     * Non-numeric "value" tokens a presence/state-style health sensor
     * (e.g. "psu1-state") can report. Verification status differs per
     * token: "fail" is independently confirmed (MikroTik forum posts on
     * PSU state reporting; official docs do not enumerate the full set).
     * "ok", "not-present", "idle", and "no-input" are plausible by
     * RouterOS's general status-naming convention but not independently
     * confirmed from public documentation — treated as known tokens here
     * on a best-effort basis. Re-verify against a real device if this
     * list needs to be authoritative.
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

    /**
     * Builds an InterfaceCollection from one verified RouterOS REST call:
     * `GET /rest/interface`. Real-device verification (RouterOS 7.10.2,
     * RB3011UiAS) confirmed this single response already contains every
     * field this DTO exposes — including counters — so no second
     * "stats-detail" call is needed (see RouterInterface's docblock for
     * why an earlier version of this package made two calls here).
     */
    public function normalizeInterfaces(array $raw): InterfaceCollection
    {
        $interfaces = [];

        foreach ($this->asRowList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = $this->stringOrNull($row, 'name');
            if ($name === null) {
                continue;
            }

            $interfaces[$name] = new RouterInterface(
                name: $name,
                type: $this->stringOrNull($row, 'type'),
                running: $this->boolOrNull($row, 'running'),
                disabled: $this->boolOrNull($row, 'disabled'),
                mtu: $this->intOrNull($row, 'mtu'),
                macAddress: $this->stringOrNull($row, 'mac-address'),
                comment: $this->stringOrNull($row, 'comment'),
                rxByte: $this->intOrNull($row, 'rx-byte'),
                txByte: $this->intOrNull($row, 'tx-byte'),
                rxPacket: $this->intOrNull($row, 'rx-packet'),
                txPacket: $this->intOrNull($row, 'tx-packet'),
                rxError: $this->intOrNull($row, 'rx-error'),
                txError: $this->intOrNull($row, 'tx-error'),
                rxDrop: $this->intOrNull($row, 'rx-drop'),
                txDrop: $this->intOrNull($row, 'tx-drop'),
                txQueueDrop: $this->intOrNull($row, 'tx-queue-drop'),
                linkDowns: $this->intOrNull($row, 'link-downs'),
                lastLinkDownTime: $this->stringOrNull($row, 'last-link-down-time'),
                lastLinkUpTime: $this->stringOrNull($row, 'last-link-up-time'),
                raw: $row,
            );
        }

        return new InterfaceCollection($interfaces);
    }

    /**
     * Builds an InterfaceRate from a raw `/interface/monitor-traffic`
     * ("once") response — verified directly against a real device to be
     * a JSON array containing a single object (see InterfaceRate's
     * docblock for the exact request/response captured).
     */
    public function normalizeInterfaceRate(array $raw): InterfaceRate
    {
        $record = $this->firstRecord($raw);

        return new InterfaceRate(
            name: $this->stringOrNull($record, 'name'),
            rxBitsPerSecond: $this->intOrNull($record, 'rx-bits-per-second'),
            txBitsPerSecond: $this->intOrNull($record, 'tx-bits-per-second'),
            rxPacketsPerSecond: $this->intOrNull($record, 'rx-packets-per-second'),
            txPacketsPerSecond: $this->intOrNull($record, 'tx-packets-per-second'),
            rxErrorsPerSecond: $this->intOrNull($record, 'rx-errors-per-second'),
            txErrorsPerSecond: $this->intOrNull($record, 'tx-errors-per-second'),
            rxDropsPerSecond: $this->intOrNull($record, 'rx-drops-per-second'),
            txDropsPerSecond: $this->intOrNull($record, 'tx-drops-per-second'),
            txQueueDropsPerSecond: $this->intOrNull($record, 'tx-queue-drops-per-second'),
            fpRxBitsPerSecond: $this->intOrNull($record, 'fp-rx-bits-per-second'),
            fpTxBitsPerSecond: $this->intOrNull($record, 'fp-tx-bits-per-second'),
            fpRxPacketsPerSecond: $this->intOrNull($record, 'fp-rx-packets-per-second'),
            fpTxPacketsPerSecond: $this->intOrNull($record, 'fp-tx-packets-per-second'),
            raw: $record,
        );
    }

    /**
     * Builds a LogCollection from a raw `/rest/log` payload — a JSON
     * array of records, per RouterOS REST's documented list-menu
     * convention (confirmed for e.g. /rest/ip/address; not independently
     * re-captured for /log specifically, but /log is structurally the
     * same kind of multi-row "print" menu). $time and $topics are kept
     * as raw strings — see LogEntry's docblock for why.
     */
    public function normalizeLogs(array $raw): LogCollection
    {
        $entries = [];

        foreach ($this->asRowList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $entries[] = new LogEntry(
                id: $this->stringOrNull($row, '.id'),
                time: $this->stringOrNull($row, 'time'),
                topics: $this->stringOrNull($row, 'topics'),
                message: $this->stringOrNull($row, 'message'),
                raw: $row,
            );
        }

        return new LogCollection($entries);
    }

    /**
     * Builds a list of IpAddress (P13) from a raw `/ip/address` "print"
     * (GET) payload — a JSON array of records, the same documented
     * list-menu shape every other list endpoint already uses.
     *
     * @return list<IpAddress>
     */
    public function normalizeIpAddresses(array $raw): array
    {
        $items = [];

        foreach ($this->asRowList($raw) as $row) {
            if (is_array($row)) {
                $items[] = $this->normalizeIpAddress($row);
            }
        }

        return $items;
    }

    /**
     * Builds one IpAddress (P13) from a raw `/ip/address` row — used for
     * both a single-item GET/find() and the full-object response
     * MikroTik's own documentation confirms PUT (add) and PATCH (set)
     * both return (help.mikrotik.com "REST API").
     */
    public function normalizeIpAddress(array $raw): IpAddress
    {
        $record = $this->firstRecord($raw);

        return new IpAddress(
            id: $this->stringOrNull($record, '.id'),
            address: $this->stringOrNull($record, 'address'),
            network: $this->stringOrNull($record, 'network'),
            interface: $this->stringOrNull($record, 'interface'),
            actualInterface: $this->stringOrNull($record, 'actual-interface'),
            disabled: $this->boolOrNull($record, 'disabled'),
            dynamic: $this->boolOrNull($record, 'dynamic'),
            invalid: $this->boolOrNull($record, 'invalid'),
            comment: $this->stringOrNull($record, 'comment'),
            raw: $record,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function asRowList(array $raw): array
    {
        if ($this->isList($raw)) {
            return array_values(array_filter($raw, 'is_array'));
        }

        // A single record, not wrapped in a list.
        return $raw === [] ? [] : [$raw];
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

    /**
     * RouterOS REST encodes booleans as the literal strings "true"/
     * "false" (confirmed: help.mikrotik.com "REST API" — every value is
     * a JSON string). A blind `(bool) $value` cast is wrong here since
     * PHP casts the non-empty string "false" to `true`. Only the exact
     * tokens "true"/"false" are recognised; anything else is left null
     * rather than guessed at.
     */
    private function boolOrNull(array $record, string $key): ?bool
    {
        if (!isset($record[$key]) || !is_scalar($record[$key])) {
            return null;
        }

        $value = strtolower((string) $record[$key]);

        return match ($value) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }
}
