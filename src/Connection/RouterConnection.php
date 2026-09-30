<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Connection;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\HealthReading;
use Easybdit\LaravelMikrotik\DTO\InterfaceCollection;
use Easybdit\LaravelMikrotik\DTO\InterfaceRate;
use Easybdit\LaravelMikrotik\DTO\LogCollection;
use Easybdit\LaravelMikrotik\DTO\RouterResource;
use Easybdit\LaravelMikrotik\Resources\DhcpResource;
use Easybdit\LaravelMikrotik\Resources\DnsResource;
use Easybdit\LaravelMikrotik\Resources\FirewallResource;
use Easybdit\LaravelMikrotik\Resources\InterfaceResource;
use Easybdit\LaravelMikrotik\Resources\IpResource;
use Easybdit\LaravelMikrotik\Resources\PppResource;
use Easybdit\LaravelMikrotik\Resources\QueueResource;
use Easybdit\LaravelMikrotik\Resources\SystemIdentityResource;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;

/**
 * One configured, named MikroTik router. This is the object returned by
 * Mikrotik::connection('name') and is the package's main public API
 * surface — consumers never touch a Transport or ResponseNormalizer
 * directly.
 */
final class RouterConnection
{
    public function __construct(
        private readonly string $name,
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    /** The connection name this instance was resolved for (e.g. "office-main"). */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Router identity/resource info (RouterOS `/system/resource`).
     */
    public function resource(): RouterResource
    {
        return $this->normalizer->normalizeResource($this->transport->get('/system/resource'));
    }

    /**
     * Hardware health sensors (RouterOS `/system/health`). The returned
     * set of sensors varies by device — see HealthReading/HealthSensor.
     */
    public function health(): HealthReading
    {
        return $this->normalizer->normalizeHealth($this->transport->get('/system/health'));
    }

    /**
     * Router interfaces (RouterOS `/interface`), including cumulative
     * traffic counters (bytes/packets since the interface last reset —
     * typically the last reboot). This is NOT a live/instantaneous
     * throughput reading — see interfaceRate() for a one-shot rate
     * reading, or poll this method twice and divide the counter delta
     * by the elapsed interval to compute your own throughput.
     */
    public function interfaces(): InterfaceCollection
    {
        return $this->normalizer->normalizeInterfaces($this->transport->get('/interface'));
    }

    /**
     * A one-shot traffic-rate snapshot for a single interface (RouterOS
     * `/interface monitor-traffic ... once`). Unlike interfaces()'s
     * cumulative counters, these are instantaneous rates RouterOS itself
     * computes over a brief internal sampling window at the moment of
     * the call — still not a live/streaming reading (RouterOS's REST API
     * has no supported way to keep a "monitor" command running; each
     * call here is a fresh, independent request).
     *
     * @throws \Easybdit\LaravelMikrotik\Exceptions\RouterOsException If $interfaceName does not exist on the router.
     */
    public function interfaceRate(string $interfaceName): InterfaceRate
    {
        return $this->normalizer->normalizeInterfaceRate(
            $this->transport->post('/interface/monitor-traffic', ['interface' => $interfaceName, 'once' => ''])
        );
    }

    /**
     * Router log entries (RouterOS `/log`), most-recent-first-or-last
     * exactly as RouterOS returns them (this package does not reorder
     * them). $filter is sent as simple equality query parameters (e.g.
     * ['topics' => 'critical']) — RouterOS REST's documented GET
     * query-string filtering form. This does not support RouterOS's
     * `~` (contains/regex) console filter operator.
     *
     * @param array<string, scalar> $filter
     */
    public function logs(array $filter = []): LogCollection
    {
        return $this->normalizer->normalizeLogs($this->transport->get('/log', $filter));
    }

    /**
     * Generic, read-only access to any RouterOS REST menu this package
     * does not (or does not yet) expose a named/typed method for, e.g.
     * `$router->menu('ip/address')->get()`. See Menu's own docblock for
     * exactly what it does and does not do — in short: GET-only, no
     * write operations, raw untyped rows, no change to any other method
     * on this class.
     *
     * @throws \Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException $path is not a plain, safe RouterOS menu path.
     */
    public function menu(string $path): Menu
    {
        return new Menu($this->transport, $path);
    }

    /**
     * Typed, read+write access to RouterOS's `/ip/*` menus (P13 —
     * currently `ip()->addresses()` only, mapping to `/ip/address`; see
     * Resources\IpResource). Uses P12's write transport
     * (put()/patch()/delete()) directly — this is unrelated to menu()
     * above, which remains GET-only.
     */
    public function ip(): IpResource
    {
        return new IpResource($this->transport, $this->normalizer);
    }

    /**
     * Typed, read+write access to RouterOS's `/interface` menu (P14):
     * list()/find()/update()/enable()/disable(). Deliberately named
     * singular ("interface", not "interfaces") to avoid any confusion
     * with interfaces() above, which is unrelated, unchanged, and
     * remains read-only — this uses P12's write transport directly, the
     * same relationship ip() has with menu().
     */
    public function interface(): InterfaceResource
    {
        return new InterfaceResource($this->transport, $this->normalizer);
    }

    /**
     * Typed, read+write access to RouterOS's `/ip/firewall/*` menus
     * (P15) — currently `firewall()->filter()` only, mapping to
     * `/ip/firewall/filter`; see Resources\FirewallResource.
     */
    public function firewall(): FirewallResource
    {
        return new FirewallResource($this->transport, $this->normalizer);
    }

    /**
     * Typed, read+write access to RouterOS's `/ip/dhcp-server*` menus
     * (P16): `dhcp()->servers()` (`/ip/dhcp-server`) and
     * `dhcp()->leases()` (`/ip/dhcp-server/lease`); see
     * Resources\DhcpResource.
     */
    public function dhcp(): DhcpResource
    {
        return new DhcpResource($this->transport, $this->normalizer);
    }

    /**
     * Typed, read+write access to RouterOS's `/ppp/*` menus (P17) —
     * currently `ppp()->secrets()` only, mapping to `/ppp/secret`; see
     * Resources\PppResource and PppSecretResource's security docblock
     * for how PPP passwords are handled.
     */
    public function ppp(): PppResource
    {
        return new PppResource($this->transport, $this->normalizer);
    }

    /**
     * Typed, read+write access to RouterOS's `/queue/*` menus (P18) —
     * currently `queue()->simple()` only, mapping to `/queue/simple`;
     * see Resources\QueueResource.
     */
    public function queue(): QueueResource
    {
        return new QueueResource($this->transport, $this->normalizer);
    }

    /**
     * Typed, read+write access to RouterOS's `/ip/dns` menu (P21) — a
     * singleton settings object (get()/update() only, no list). See
     * Resources\DnsResource's docblock.
     */
    public function dns(): DnsResource
    {
        return new DnsResource($this->transport, $this->normalizer);
    }

    /**
     * Typed, read+write access to RouterOS's `/system/identity` menu
     * (P21) — a singleton settings object (get()/update() only, no
     * list). See Resources\SystemIdentityResource's docblock.
     */
    public function systemIdentity(): SystemIdentityResource
    {
        return new SystemIdentityResource($this->transport, $this->normalizer);
    }
}
