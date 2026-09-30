<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Support;

use Easybdit\LaravelMikrotik\DTO\DhcpLease;
use Easybdit\LaravelMikrotik\DTO\DhcpServer;
use Easybdit\LaravelMikrotik\DTO\DnsSettings;
use Easybdit\LaravelMikrotik\DTO\FirewallFilterRule;
use Easybdit\LaravelMikrotik\DTO\HealthReading;
use Easybdit\LaravelMikrotik\DTO\HealthSensor;
use Easybdit\LaravelMikrotik\DTO\InterfaceCollection;
use Easybdit\LaravelMikrotik\DTO\InterfaceRate;
use Easybdit\LaravelMikrotik\DTO\InterfaceRecord;
use Easybdit\LaravelMikrotik\DTO\IpAddress;
use Easybdit\LaravelMikrotik\DTO\IpPool;
use Easybdit\LaravelMikrotik\DTO\IpRoute;
use Easybdit\LaravelMikrotik\DTO\LogCollection;
use Easybdit\LaravelMikrotik\DTO\LogEntry;
use Easybdit\LaravelMikrotik\DTO\PppSecret;
use Easybdit\LaravelMikrotik\DTO\RouterInterface;
use Easybdit\LaravelMikrotik\DTO\RouterResource;
use Easybdit\LaravelMikrotik\DTO\SimpleQueue;
use Easybdit\LaravelMikrotik\DTO\SystemIdentity;

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
     * Builds a list of InterfaceRecord (P14) from a raw `/interface`
     * "print" (GET) payload — the same documented list-menu shape every
     * other list endpoint already uses.
     *
     * @return list<InterfaceRecord>
     */
    public function normalizeInterfaceRecords(array $raw): array
    {
        $items = [];

        foreach ($this->asRowList($raw) as $row) {
            if (is_array($row)) {
                $items[] = $this->normalizeInterfaceRecord($row);
            }
        }

        return $items;
    }

    /**
     * Builds one InterfaceRecord (P14) from a raw `/interface` row —
     * used for a single-item GET/find() and the object PATCH (set)
     * returns, following the same convention normalizeIpAddress() uses.
     */
    public function normalizeInterfaceRecord(array $raw): InterfaceRecord
    {
        $record = $this->firstRecord($raw);

        return new InterfaceRecord(
            id: $this->stringOrNull($record, '.id'),
            name: $this->stringOrNull($record, 'name'),
            type: $this->stringOrNull($record, 'type'),
            running: $this->boolOrNull($record, 'running'),
            disabled: $this->boolOrNull($record, 'disabled'),
            comment: $this->stringOrNull($record, 'comment'),
            raw: $record,
        );
    }

    /** Builds a list of FirewallFilterRule (P15) from a raw `/ip/firewall/filter` "print" payload. @return list<FirewallFilterRule> */
    public function normalizeFirewallFilterRules(array $raw): array
    {
        $items = [];
        foreach ($this->asRowList($raw) as $row) {
            if (is_array($row)) {
                $items[] = $this->normalizeFirewallFilterRule($row);
            }
        }

        return $items;
    }

    /** Builds one FirewallFilterRule (P15) from a raw `/ip/firewall/filter` row. */
    public function normalizeFirewallFilterRule(array $raw): FirewallFilterRule
    {
        $record = $this->firstRecord($raw);

        return new FirewallFilterRule(
            id: $this->stringOrNull($record, '.id'),
            chain: $this->stringOrNull($record, 'chain'),
            action: $this->stringOrNull($record, 'action'),
            protocol: $this->stringOrNull($record, 'protocol'),
            srcAddress: $this->stringOrNull($record, 'src-address'),
            dstAddress: $this->stringOrNull($record, 'dst-address'),
            srcPort: $this->stringOrNull($record, 'src-port'),
            dstPort: $this->stringOrNull($record, 'dst-port'),
            inInterface: $this->stringOrNull($record, 'in-interface'),
            outInterface: $this->stringOrNull($record, 'out-interface'),
            disabled: $this->boolOrNull($record, 'disabled'),
            comment: $this->stringOrNull($record, 'comment'),
            raw: $record,
        );
    }

    /** Builds a list of DhcpServer (P16) from a raw `/ip/dhcp-server` "print" payload. @return list<DhcpServer> */
    public function normalizeDhcpServers(array $raw): array
    {
        $items = [];
        foreach ($this->asRowList($raw) as $row) {
            if (is_array($row)) {
                $items[] = $this->normalizeDhcpServer($row);
            }
        }

        return $items;
    }

    /** Builds one DhcpServer (P16) from a raw `/ip/dhcp-server` row. */
    public function normalizeDhcpServer(array $raw): DhcpServer
    {
        $record = $this->firstRecord($raw);

        return new DhcpServer(
            id: $this->stringOrNull($record, '.id'),
            name: $this->stringOrNull($record, 'name'),
            interface: $this->stringOrNull($record, 'interface'),
            addressPool: $this->stringOrNull($record, 'address-pool'),
            leaseTime: $this->stringOrNull($record, 'lease-time'),
            disabled: $this->boolOrNull($record, 'disabled'),
            comment: $this->stringOrNull($record, 'comment'),
            raw: $record,
        );
    }

    /** Builds a list of DhcpLease (P16) from a raw `/ip/dhcp-server/lease` "print" payload. @return list<DhcpLease> */
    public function normalizeDhcpLeases(array $raw): array
    {
        $items = [];
        foreach ($this->asRowList($raw) as $row) {
            if (is_array($row)) {
                $items[] = $this->normalizeDhcpLease($row);
            }
        }

        return $items;
    }

    /** Builds one DhcpLease (P16) from a raw `/ip/dhcp-server/lease` row. */
    public function normalizeDhcpLease(array $raw): DhcpLease
    {
        $record = $this->firstRecord($raw);

        return new DhcpLease(
            id: $this->stringOrNull($record, '.id'),
            address: $this->stringOrNull($record, 'address'),
            macAddress: $this->stringOrNull($record, 'mac-address'),
            server: $this->stringOrNull($record, 'server'),
            hostName: $this->stringOrNull($record, 'host-name'),
            status: $this->stringOrNull($record, 'status'),
            dynamic: $this->boolOrNull($record, 'dynamic'),
            disabled: $this->boolOrNull($record, 'disabled'),
            comment: $this->stringOrNull($record, 'comment'),
            raw: $record,
        );
    }

    /**
     * Builds a list of PppSecret (P17) from a raw `/ppp/secret` "print"
     * payload. See PppSecret's docblock: `password` is stripped from
     * every row before it reaches this object or the DTO.
     *
     * @return list<PppSecret>
     */
    public function normalizePppSecrets(array $raw): array
    {
        $items = [];
        foreach ($this->asRowList($raw) as $row) {
            if (is_array($row)) {
                $items[] = $this->normalizePppSecret($row);
            }
        }

        return $items;
    }

    /**
     * Builds one PppSecret (P17) from a raw `/ppp/secret` row. `password`
     * is removed from $raw here, before it is ever stored on the DTO —
     * see PppSecret's docblock for why this is a deliberate exception to
     * this package's usual "raw is untouched" convention.
     */
    public function normalizePppSecret(array $raw): PppSecret
    {
        $record = $this->firstRecord($raw);

        foreach (PppSecret::REDACTED_FIELDS as $field) {
            unset($record[$field]);
        }

        return new PppSecret(
            id: $this->stringOrNull($record, '.id'),
            name: $this->stringOrNull($record, 'name'),
            service: $this->stringOrNull($record, 'service'),
            callerId: $this->stringOrNull($record, 'caller-id'),
            profile: $this->stringOrNull($record, 'profile'),
            remoteAddress: $this->stringOrNull($record, 'remote-address'),
            disabled: $this->boolOrNull($record, 'disabled'),
            comment: $this->stringOrNull($record, 'comment'),
            raw: $record,
        );
    }

    /** Builds a list of SimpleQueue (P18) from a raw `/queue/simple` "print" payload. @return list<SimpleQueue> */
    public function normalizeSimpleQueues(array $raw): array
    {
        $items = [];
        foreach ($this->asRowList($raw) as $row) {
            if (is_array($row)) {
                $items[] = $this->normalizeSimpleQueue($row);
            }
        }

        return $items;
    }

    /** Builds one SimpleQueue (P18) from a raw `/queue/simple` row. */
    public function normalizeSimpleQueue(array $raw): SimpleQueue
    {
        $record = $this->firstRecord($raw);

        return new SimpleQueue(
            id: $this->stringOrNull($record, '.id'),
            name: $this->stringOrNull($record, 'name'),
            target: $this->stringOrNull($record, 'target'),
            maxLimit: $this->stringOrNull($record, 'max-limit'),
            parent: $this->stringOrNull($record, 'parent'),
            priority: $this->stringOrNull($record, 'priority'),
            disabled: $this->boolOrNull($record, 'disabled'),
            comment: $this->stringOrNull($record, 'comment'),
            raw: $record,
        );
    }

    /** Builds a list of IpPool (P19) from a raw `/ip/pool` "print" payload. @return list<IpPool> */
    public function normalizeIpPools(array $raw): array
    {
        $items = [];
        foreach ($this->asRowList($raw) as $row) {
            if (is_array($row)) {
                $items[] = $this->normalizeIpPool($row);
            }
        }

        return $items;
    }

    /** Builds one IpPool (P19) from a raw `/ip/pool` row. */
    public function normalizeIpPool(array $raw): IpPool
    {
        $record = $this->firstRecord($raw);

        return new IpPool(
            id: $this->stringOrNull($record, '.id'),
            name: $this->stringOrNull($record, 'name'),
            ranges: $this->stringOrNull($record, 'ranges'),
            raw: $record,
        );
    }

    /** Builds a list of IpRoute (P20) from a raw `/ip/route` "print" payload. @return list<IpRoute> */
    public function normalizeIpRoutes(array $raw): array
    {
        $items = [];
        foreach ($this->asRowList($raw) as $row) {
            if (is_array($row)) {
                $items[] = $this->normalizeIpRoute($row);
            }
        }

        return $items;
    }

    /** Builds one IpRoute (P20) from a raw `/ip/route` row. */
    public function normalizeIpRoute(array $raw): IpRoute
    {
        $record = $this->firstRecord($raw);

        return new IpRoute(
            id: $this->stringOrNull($record, '.id'),
            dstAddress: $this->stringOrNull($record, 'dst-address'),
            gateway: $this->stringOrNull($record, 'gateway'),
            distance: $this->intOrNull($record, 'distance'),
            scope: $this->stringOrNull($record, 'scope'),
            targetScope: $this->stringOrNull($record, 'target-scope'),
            inactive: $this->boolOrNull($record, 'inactive'),
            disabled: $this->boolOrNull($record, 'disabled'),
            comment: $this->stringOrNull($record, 'comment'),
            raw: $record,
        );
    }

    /**
     * Builds DnsSettings (P21) from a raw `/ip/dns` response — a single
     * JSON object (not a list), confirmed directly against a real
     * device.
     */
    public function normalizeDnsSettings(array $raw): DnsSettings
    {
        $record = $this->firstRecord($raw);

        return new DnsSettings(
            servers: $this->stringOrNull($record, 'servers'),
            allowRemoteRequests: $this->boolOrNull($record, 'allow-remote-requests'),
            cacheSize: $this->stringOrNull($record, 'cache-size'),
            cacheMaxTtl: $this->stringOrNull($record, 'cache-max-ttl'),
            raw: $record,
        );
    }

    /**
     * Builds SystemIdentity (P21) from a raw `/system/identity`
     * response — a single JSON object with one field (`name`),
     * confirmed directly against a real device.
     */
    public function normalizeSystemIdentity(array $raw): SystemIdentity
    {
        $record = $this->firstRecord($raw);

        return new SystemIdentity(
            name: $this->stringOrNull($record, 'name'),
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
