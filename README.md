# easybdit/laravel-mikrotik

A Laravel package for talking to a MikroTik RouterOS device over its
**REST API**. It gives you a typed, Laravel-friendly way to read a
router's status and (for a growing, deliberately small set of menus)
change its configuration, plus optional database-backed monitoring
(snapshots, threshold alerts, incidents, notifications) built on top of
the same reads.

**Current release: P1 through P21, plus P23-P25.** Everything
documented below is implemented, tested, and — where marked — verified
against a real RouterOS device. Nothing in this README describes a
feature that does not exist yet; planned future work is listed
separately in [Roadmap](#19-roadmap) and marked **Planned**, never as
available today. (P22 — user/scheduler management — was intentionally
skipped in favor of P23-P25's query/performance/release-hardening work
and remains **Planned**; see [Roadmap](#19-roadmap).)

## 1. What this package is

If you've never used this package before, here is the shape of it:

- You configure one or more **connections** (router host + credentials)
  in `config/mikrotik.php` / `.env` — the same "named connection" idea
  Laravel uses for `config/database.php`.
- You get a `RouterConnection` object (via the `Mikrotik` facade) that
  talks to that router's REST API over HTTPS.
- Everything it returns is a typed PHP object (a DTO), not a raw array —
  so `$resource->cpuLoad` instead of guessing at JSON keys.

Capabilities fall into three groups:

**Read capabilities** — get information from the router:

- `resource()` — board/CPU/memory info (`/system/resource`)
- `health()` — hardware sensors, e.g. temperature/voltage (`/system/health`)
- `interfaces()` — every interface, with cumulative traffic counters (`/interface`)
- `interfaceRate()` — a one-shot live traffic-rate reading for one interface
- `logs()` — router log entries (`/log`)
- `menu()` — generic **read-only** access to any other RouterOS menu
- `ip()->addresses()->list()`/`find()` — typed reads of `/ip/address`
- `interface()->list()`/`find()` — typed reads of `/interface`
- `firewall()->filter()->list()`/`find()` — typed reads of `/ip/firewall/filter`
- `dhcp()->servers()->list()`/`find()`, `dhcp()->leases()->list()`/`find()` — typed reads of `/ip/dhcp-server`, `/ip/dhcp-server/lease`
- `ppp()->secrets()->list()`/`find()` — typed reads of `/ppp/secret` (passwords never included — see [PPP/PPPoE secret management](#7e-ppppppoe-secret-management-p17))
- `queue()->simple()->list()`/`find()` — typed reads of `/queue/simple`
- `ip()->pools()->list()`/`find()`, `ip()->routes()->list()`/`find()` — typed reads of `/ip/pool`, `/ip/route`
- `dns()->get()`, `systemIdentity()->get()` — typed reads of `/ip/dns`, `/system/identity`

**Monitoring** — optional, database-backed, built entirely on the reads
above (nothing here talks to RouterOS on its own beyond what `resource()`/
`health()`/`interfaces()` already do):

- Snapshots — a point-in-time capture stored in your own database
- Rules — threshold conditions evaluated against a snapshot
- Alerts — a triggered/resolved lifecycle over rule matches
- Incidents — a stable record grouping one alert's episode
- Notifications — optional mail/webhook delivery when an alert changes state
- Analytics — read-only trend/history queries over everything above
- `mikrotik:monitor` — one artisan command that ties snapshots + rules +
  incidents + notifications together, meant for Laravel's scheduler

**Write capabilities** — change the router's configuration:

- P12 added an internal write transport (`PUT`/`PATCH`/`DELETE`), not
  exposed directly — it exists only so the typed resources below can be
  built on it.
- P13 exposed the first typed write resource: `ip()->addresses()` —
  full `list()`/`find()`/`add()`/`update()`/`remove()`/`enable()`/`disable()`
  for RouterOS's `/ip/address` menu.
- P14 added a second: `interface()` — `list()`/`find()`/`update()`/
  `enable()`/`disable()` for RouterOS's `/interface` menu (no `add()`/
  `remove()` — see [Interface management](#7b-interface-management-p14)).
- P15-P21 added seven more: `firewall()->filter()`, `dhcp()->servers()`/
  `dhcp()->leases()`, `ppp()->secrets()`, `queue()->simple()`,
  `ip()->pools()`, `ip()->routes()`, and the singleton settings
  resources `dns()`/`systemIdentity()` — each following the same shape,
  with operations limited to what RouterOS actually supports (e.g. no
  `enable()`/`disable()` on `ip()->pools()`, no `add()`/`remove()` on
  `dns()`/`systemIdentity()`). See [section 7](#7-ip-address-management)
  onward for each one.
- **`menu()` is, and will always remain, read-only.** It never issues a
  write request under any circumstance. Write access only exists through
  explicit, typed resource classes like the ones above.
- No other RouterOS configuration menu is implemented yet — no VPN,
  wireless, bridge, VLAN, user/scheduler, etc. See
  [Feature matrix](#18-feature-matrix) for the authoritative list of what
  exists today, and [Roadmap](#19-roadmap) for what's planned.

## 2. Requirements

- A MikroTik router running **RouterOS v7.1beta4 or later**, with the
  `www-ssl` service enabled (Winbox/WebFig → **IP → Services → www-ssl**).
  This package is **HTTPS-only by design** — RouterOS's plain-HTTP REST
  variant sends credentials as unencrypted HTTP Basic Auth, and there is
  no configuration option to use it.
- One of these PHP/Laravel combinations (from `composer.json`, and each
  individually verified installable, not just declared — see CI at
  `.github/workflows/tests.yml`):

  | Laravel | PHP |
  |---|---|
  | 10 | 8.1, 8.2, 8.3, 8.4 |
  | 11 | 8.2, 8.3, 8.4 |
  | 12 | 8.2, 8.3, 8.4 |
  | 13 | 8.3, 8.4 |

  A combination not listed (e.g. PHP 8.1 with Laravel 11+) is not
  supported — Composer cannot resolve it, since Laravel itself raised its
  minimum PHP version partway through that range.
- No PHP extensions beyond what Laravel's own HTTP client (Guzzle)
  already requires for your Laravel version — this package adds none of
  its own.
- **RouterOS user permissions:** a read-only RouterOS user (the `read`
  policy) is enough for every *read* capability above. Every *write*
  capability (`ip()->addresses()`, `interface()`, `firewall()->filter()`,
  `dhcp()->servers()`/`leases()`, `ppp()->secrets()`, `queue()->simple()`,
  `ip()->pools()`/`routes()`, `systemIdentity()`) additionally requires
  the `write` policy — confirmed directly against a real device: a
  `read`-only user gets a `RouterOsException` with RouterOS's own `"not
  enough permissions (9)"` detail on any write call. Use a dedicated,
  least-privilege RouterOS user rather than `admin` where practical.
- `verify_tls` (config/`.env`: `MIKROTIK_VERIFY_TLS`) controls whether
  this package verifies the router's TLS certificate. Leave it `true` in
  production. Only set it `false` for a router using a self-signed
  certificate you have not imported into a trusted CA store, and only if
  you understand the risk — disabling verification exposes you to
  man-in-the-middle attacks against your router credentials.

## 3. Installation

```bash
composer require easybdit/laravel-mikrotik
```

Laravel's package auto-discovery registers `MikrotikServiceProvider` and
the `Mikrotik` facade automatically — no manual provider registration
needed.

Publish the config file:

```bash
php artisan vendor:publish --tag=mikrotik-config
```

This creates `config/mikrotik.php`. Monitoring (snapshots/rules/alerts/
incidents) needs its own migrations, published separately — see
[Monitoring](#8-monitoring) below; skip that step if you only need the
read/write RouterOS API and not the database-backed monitoring features.

## 4. Basic Configuration

Set your router's connection details in your application's `.env`
(**never commit this file to Git**):

```env
MIKROTIK_CONNECTION=default
MIKROTIK_HOST=192.168.88.1
MIKROTIK_PORT=443
MIKROTIK_USERNAME=admin
MIKROTIK_PASSWORD=replace-with-your-own-password
MIKROTIK_VERIFY_TLS=true
MIKROTIK_TIMEOUT=10
MIKROTIK_RETRY_TIMES=0
MIKROTIK_RETRY_SLEEP=0
```

Every one of these maps directly to a key in `config/mikrotik.php`
(`env()` calls only — nothing here is invented):

| `.env` variable | Config key | Default |
|---|---|---|
| `MIKROTIK_CONNECTION` | `mikrotik.default` | `default` |
| `MIKROTIK_HOST` | `mikrotik.connections.default.host` | *(required, no default)* |
| `MIKROTIK_PORT` | `mikrotik.connections.default.port` | `443` |
| `MIKROTIK_USERNAME` | `mikrotik.connections.default.username` | `admin` |
| `MIKROTIK_PASSWORD` | `mikrotik.connections.default.password` | `''` |
| `MIKROTIK_VERIFY_TLS` | `mikrotik.connections.default.verify_tls` | `true` |
| `MIKROTIK_TIMEOUT` | `mikrotik.connections.default.timeout` | `10` (seconds) |
| `MIKROTIK_RETRY_TIMES` | `mikrotik.connections.default.retry.times` | `0` (no retry) |
| `MIKROTIK_RETRY_SLEEP` | `mikrotik.connections.default.retry.sleep` | `0` (milliseconds) |

To monitor more than one router, add additional named entries directly
in `config/mikrotik.php` (not `.env` — Laravel config files, not `.env`,
are where you define multiple connections, the same way
`config/database.php` works):

```php
'connections' => [
    'default' => [ /* ... */ ],

    'branch-01' => [
        'transport'  => 'rest',
        'host'       => env('MIKROTIK_BRANCH01_HOST'),
        'username'   => env('MIKROTIK_BRANCH01_USERNAME'),
        'password'   => env('MIKROTIK_BRANCH01_PASSWORD'),
        'verify_tls' => true,
        'timeout'    => 10,
    ],
],
```

**Never commit real credentials.** Keep `.env` out of version control
(Laravel's default `.gitignore` already does this) and never paste a
real password into a bug report, log message, or this package's tests.

## 5. First Connection

The smallest working example:

```php
use Easybdit\LaravelMikrotik\Facades\Mikrotik;

$router = Mikrotik::connection();            // the default connection
$router = Mikrotik::connection('branch-01'); // a specific named connection

$resource = $router->resource();
echo $resource->version;      // e.g. "7.15 (stable)"
echo $resource->boardName;    // e.g. "RB3011UiAS"
echo $resource->cpuLoad;      // int, percent
echo $resource->freeMemory;   // int, bytes

$health = $router->health();
if ($health->has('cpu-temperature')) {
    echo $health->get('cpu-temperature')->value; // e.g. 43 (int)
    echo $health->get('cpu-temperature')->type;  // e.g. "C"
}
// Devices expose different sensors — iterate whatever is actually there:
foreach ($health as $sensor) {
    echo "{$sensor->name}: {$sensor->value}{$sensor->type}\n";
}

$interfaces = $router->interfaces();
$eth1 = $interfaces->get('ether1');
echo $eth1->type;      // "ether"
echo $eth1->running;   // bool
echo $eth1->rxByte;    // int, cumulative bytes received since last reset/reboot
```

**Every field on every DTO is nullable** (except an item's own `.id`
where one exists) — a field a particular RouterOS build/board doesn't
report simply comes through as `null` instead of raising an error.
`$dto->raw` (or `->raw()` on a collection-style object) always has the
complete, untouched response if you need a field this package doesn't
explicitly type yet.

`resource()`/`health()` return a single object; `interfaces()`/`logs()`
return an iterable collection-like object; see
[Architecture overview](#17-architecture-overview) for how these fit
together, and the sections below for `interfaceRate()`, `logs()`, and
the write APIs.

### Interfaces and traffic counters

```php
foreach ($router->interfaces() as $interface) {
    echo "{$interface->name}: {$interface->rxByte} rx / {$interface->txByte} tx\n";
}
```

**Traffic counters are cumulative, not live.** `rxByte`/`txByte`/
`rxPacket`/`txPacket`/`rxError`/`txError`/`rxDrop`/`txDrop` are totals
since the interface's counters were last reset — in practice, since the
last reboot. A reboot resets these to zero; a delta computed across a
reboot reads as negative/nonsensical, not as an error — this package
does not detect or guard against that. For a live rate, either use
`interfaceRate()` below, or poll `interfaces()` twice yourself:

```php
$before = $router->interfaces()->get('ether1')->rxByte;
sleep(5);
$after = $router->interfaces()->get('ether1')->rxByte;
$bytesPerSecond = ($after - $before) / 5;
```

### One-shot interface rate

```php
$rate = $router->interfaceRate('ether1');
echo $rate->rxBitsPerSecond;    // int
echo $rate->txBitsPerSecond;    // int
echo $rate->rxPacketsPerSecond; // int
```

This calls RouterOS's `/interface monitor-traffic ... once` — a single
instantaneous rate reading RouterOS computes over a brief internal
sampling window, **not** a live/streaming subscription. Each call makes
a fresh request; nothing is cached or held open. An invalid interface
name throws `RouterOsException` with RouterOS's own message.

### Logs

```php
foreach ($router->logs() as $entry) {
    echo "{$entry->time} [{$entry->topics}] {$entry->message}\n";
}

// Simple equality filtering (sent as a REST query-string parameter):
$criticalOnly = $router->logs(['topics' => 'critical']);
```

`$entry->time`/`$entry->topics` are kept exactly as RouterOS returns
them and are **not** parsed — RouterOS's console timestamp omits the
date for today's entries and omits the year entirely, so there is no
single safe format to normalize into. Filtering only supports simple
equality (`?field=value`); RouterOS's console-only `~` (contains/regex)
operator is not supported over REST.

## 6. Generic Menu API

`resource()`/`health()`/`interfaces()`/`interfaceRate()`/`logs()` are
named, typed methods for five specific menus. For anything else,
`menu()` gives generic **read-only** access to any RouterOS REST menu:

```php
$addresses = $router->menu('ip/address')->get();

foreach ($addresses as $address) {
    echo "{$address['address']} on {$address['interface']}\n";
}

// Simple equality filtering — the same `?field=value` form logs() uses:
$onBridge1 = $router->menu('ip/address')->get(['interface' => 'bridge1']);

// A single item by its RouterOS ".id" (e.g. "*1") or, where the menu
// supports it, its name (e.g. "ether1"):
$item = $router->menu('interface')->find('ether1');
```

- **Read-only.** `menu()` never issues a write request — there is no
  `add()`/`set()`/`remove()`/`enable()`/`disable()` on it, and this will
  never change. Write access only ever exists through explicit typed
  resources like `ip()->addresses()`/`interface()`.
- **`get()`** returns a plain `Illuminate\Support\Collection` of **raw,
  untyped associative arrays** — exactly RouterOS's REST JSON as-is
  (every value still a JSON-encoded string, per RouterOS REST's own
  convention). Unlike the named DTOs, `menu()` has no fixed schema for an
  arbitrary path, so it never guesses field types — normalize whatever
  fields you need yourself.
- **`find()`** does not convert a "not found" condition to `null` — any
  RouterOS-side error, including "not found", surfaces as
  `RouterOsException` (or `AuthenticationException`/`ConnectionException`,
  as appropriate), the same as every other method in this package.
- Every menu path and item identifier is validated against a strict
  allow-list before a request is ever sent — a path traversal (`..`), an
  absolute URL, or protocol-like input (`http://...`) throws
  `InvalidMenuPathException` immediately, without reaching the router.

### Advanced query/filtering (P23)

`get()`'s simple `?field=value` filtering can only express "AND" —
every condition must match. For anything `get()` can't express (like
"OR"), or to limit which fields RouterOS returns, `query()` uses
RouterOS REST's own documented `.query`/`.proplist` mechanism:

```php
// OR: everything that's type=ether OR type=bridge --
// confirmed against a real device: 11 ether + 1 bridge = 12 rows.
$router->menu('interface')->query(['type=ether', 'type=bridge', '#|']);

// Limit which fields come back (smaller/faster response):
$router->menu('ip/address')->query(['dynamic=true'], proplist: ['.id', 'address']);

// proplist alone, no filter:
$router->menu('interface')->query(proplist: ['name', 'type']);
```

- `$words` is RouterOS's own `.query` "query stack", sent verbatim: a
  list of `"field=value"` conditions and, where you need it, a stack
  operator RouterOS itself documents. **Only `"#|"` (OR) is confirmed**
  — directly against a real device, across two different menus. This
  package does not invent or validate RouterOS's own query-stack syntax
  beyond what is confirmed; comparison operators (`>`, `<`), `NOT`, and
  `~` (contains/regex) are **not** confirmed and are passed through
  unvalidated at your own risk — an unsupported word surfaces as
  `RouterOsException`.
- `$proplist` limits which fields RouterOS returns. **Confirmed to work
  through `query()`'s `POST` form; confirmed *not* to work as a plain
  `GET` query-string parameter** — tested directly against a real
  device, a `GET .../ip/address?.proplist=...` attempt returned a list
  of *empty* objects. This is exactly why `$proplist` is only offered
  through `query()`, never added to `get()`.
- `get()`/`find()` are completely unchanged by this.

## 7. IP Address Management

`$router->ip()->addresses()` maps to RouterOS's `/ip/address` menu — the
first typed **read+write** resource, built on P12's internal write
transport:

```php
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;

$addresses = $router->ip()->addresses();

$addresses->list();                              // Collection<DTO\IpAddress>
$addresses->list(['interface' => 'bridge1']);     // simple equality filter
$addresses->find('*1');                           // DTO\IpAddress, by ".id" or name

$created = $addresses->add([
    'address'   => '192.168.88.1/24', // required
    'interface' => 'bridge1',         // optional, but almost always needed
]);                                                // throws InvalidResourceException if 'address' is missing

$addresses->update($created->id, ['comment' => 'lan']); // any RouterOS field
$addresses->disable($created->id);                // PATCH {"disabled": "true"}
$addresses->enable($created->id);                 // PATCH {"disabled": "false"}
$addresses->remove($created->id);                 // DELETE — RouterOS's documented empty-body success
```

- **`add()`** requires `address` (RouterOS itself requires more for the
  configuration to be meaningful — e.g. `interface` — but this package
  only validates what it can safely guarantee before sending a request;
  RouterOS validates the rest and returns a `RouterOsException` if
  something else is missing/invalid).
- **`add()`/`update()`** return the full `DTO\IpAddress` RouterOS's
  documented response includes: `.id`, `address`, `network`, `interface`,
  `actual-interface`, `disabled`, `dynamic`, `invalid`, `comment` —
  anything else stays available in `->raw`.
- **`enable()`/`disable()`** are thin `update()` wrappers PATCHing the
  `disabled` field as the string `"true"`/`"false"` — RouterOS REST has
  no separate documented enable/disable endpoint. **Confirmed against a
  real device** that this string format (not `"yes"`/`"no"`) is correct.
- Every `$id` (`find()`/`update()`/`remove()`/`enable()`/`disable()`) is
  validated against the same safe-identifier allow-list `menu()` uses.
- Every RouterOS-side failure (400/404/etc.) surfaces as
  `RouterOsException`, exactly like every other method in this package.

**A safe, documentation-only example for trying this yourself:**
`203.0.113.99/32` is reserved by [RFC 5737](https://www.rfc-editor.org/rfc/rfc5737)
specifically for documentation — it is never a real, routable address on
any real network, which is why it is used throughout this README and in
this package's own real-device tests. It is still a **write** operation
against your real router's configuration — see
[Real MikroTik Testing Guide](#12-real-mikrotik-testing-guide) before
trying it, and never run this kind of example against a production
address, interface, or router you cannot afford to misconfigure.

### 7b. Interface management (P14)

A second typed **read+write** resource, alongside `ip()` above —
`$router->interface()` maps to RouterOS's `/interface` menu. Named
singular (`interface()`, not `interfaces()`) specifically to avoid any
confusion with the unrelated, **read-only** `interfaces()` from
[section 5](#5-first-connection) — that method is completely unchanged:

```php
$iface = $router->interface();

$iface->list();                              // Collection<DTO\InterfaceRecord>
$iface->list(['type' => 'ether']);           // simple equality filter
$iface->find('ether7');                      // DTO\InterfaceRecord, by name or ".id"

$iface->update('ether7', ['comment' => 'uplink']);
$iface->disable('ether7');                   // PATCH {"disabled": "true"}
$iface->enable('ether7');                    // PATCH {"disabled": "false"}
```

- **No `add()`/`remove()`.** RouterOS's own REST documentation does not
  confirm `PUT`/`DELETE` support for `/interface`, and RouterOS itself
  does not generally support creating/destroying a *physical* interface
  through this generic menu — this package does not invent behavior it
  has no documented or observed basis for.
- **`update()`** returns `DTO\InterfaceRecord` (`.id`, `name`, `type`,
  `running`, `disabled`, `comment`; `->raw` has everything else). This is
  a smaller field set than `interfaces()`'s `DTO\RouterInterface` (which
  already has the full counter set for read-only use) — the two DTOs are
  deliberately not merged.
- `$id` accepts either a RouterOS `.id` or the interface's own name
  (e.g. `"ether7"`) — confirmed against a real device.
- **Confirmed against a real device** that `disabled` uses the same
  `"true"`/`"false"` string convention as `ip()->addresses()`.

### 7c. Firewall filter management (P15)

`$router->firewall()->filter()` maps to RouterOS's `/ip/firewall/filter`
menu — full `list()`/`find()`/`add()`/`update()`/`remove()`/`enable()`/
`disable()`:

```php
$filter = $router->firewall()->filter();

$filter->list();                              // Collection<DTO\FirewallFilterRule>
$filter->list(['chain' => 'forward']);        // simple equality filter

$rule = $filter->add([
    'chain'  => 'forward', // required
    'action' => 'drop',
    'src-address' => '203.0.113.99/32', // a safe example — see the testing guide
    'disabled' => true,
]);

$filter->update($rule->id, ['comment' => 'blocks test traffic']);
$filter->disable($rule->id);
$filter->enable($rule->id);
$filter->remove($rule->id);
```

**Confirmed against a real device**: RouterOS strips a `/32`
(single-host) suffix from `src-address`/`dst-address` on write — adding
`'203.0.113.99/32'` reads back as `"203.0.113.99"` on the next `find()`.
This is RouterOS's own behavior, not something this package controls.

**This changes firewall behavior on your router — treat it with real
caution.** See [Real MikroTik Testing Guide](#12-real-mikrotik-testing-guide)
before testing against a real device: this package's own tests only
ever use a rule created **disabled**, scoped to a documentation-only
address that can never match real traffic, and removed immediately
after.

### 7d. DHCP management (P16)

`$router->dhcp()` is a namespace object for two menus:

```php
$servers = $router->dhcp()->servers(); // /ip/dhcp-server
$leases  = $router->dhcp()->leases();  // /ip/dhcp-server/lease

$servers->list();                        // Collection<DTO\DhcpServer>
$server = $servers->add(['name' => 'dhcp2', 'interface' => 'bridge2', 'disabled' => true]);
$servers->update($server->id, ['comment' => 'secondary']);
$servers->enable($server->id);
$servers->disable($server->id);
$servers->remove($server->id);

$leases->list();                         // Collection<DTO\DhcpLease>
$lease = $leases->add(['address' => '192.168.88.50', 'mac-address' => 'AA:BB:CC:DD:EE:FF']);
$leases->update($lease->id, ['comment' => 'reserved']);
$leases->remove($lease->id);
```

Both support full `list()`/`find()`/`add()`/`update()`/`remove()`/
`enable()`/`disable()`. `add()` requires `name` (server) / `address`
(lease). Most leases on a running network are **dynamic**
(RouterOS-managed) — this package does not special-case dynamic vs.
static leases; RouterOS itself governs what a write against one does.

**Never touch an active production DHCP server or an existing lease.**
This package's own real-device testing only ever added a **disabled**
test server (kept disabled for its entire test, on an idle interface)
and a test lease bound to a fake MAC address no real device uses.

### 7e. PPP/PPPoE secret management (P17)

`$router->ppp()->secrets()` maps to RouterOS's `/ppp/secret` menu — full
`list()`/`find()`/`add()`/`update()`/`remove()`/`enable()`/`disable()`:

```php
$secrets = $router->ppp()->secrets();

$secrets->list();                        // Collection<DTO\PppSecret> -- no passwords
$secret = $secrets->add([
    'name'     => 'a-new-username', // required
    'password' => $password,        // your own variable -- never hardcode one
    'service'  => 'pppoe',
    'disabled' => true,
]);
$secrets->update($secret->id, ['comment' => 'test account']);
$secrets->remove($secret->id);
```

**Security — read this before using this module.** RouterOS's own REST
API returns a secret's `password` in **plain text** on every
GET/PUT/PATCH response — confirmed directly against a real device. This
package does **not** carry that value for you:

- `DTO\PppSecret` has **no `$password` property at all**.
- `$secret->raw` has the `password` key **removed** before it ever
  reaches the DTO — the one deliberate exception to this package's
  usual "`->raw` is the untouched response" rule.
- A password therefore cannot leak into a log line, an exception, or a
  `json_encode()`/`toArray()` dump of anything this package hands you.
- Sending a password *to* RouterOS (via `add()`/`update()`, to set or
  change one) is unaffected — that is your own outbound data, not
  something this package echoes back.
- If your application genuinely needs to read a secret's password back,
  do so outside this package (e.g. `$router->menu('ppp/secret')->get()`
  — still real, still sensitive, but at least explicit about it).

**Never modify or delete an existing PPP/PPPoE user.** Use a randomly
generated, clearly temporary username for any testing, and never print,
log, or commit a real password.

### 7f. Simple Queue management (P18)

`$router->queue()->simple()` maps to RouterOS's `/queue/simple` menu —
full `list()`/`find()`/`add()`/`update()`/`remove()`/`enable()`/`disable()`:

```php
$queue = $router->queue()->simple();

$queue->list();                          // Collection<DTO\SimpleQueue>
$q = $queue->add([
    'name'      => 'guest-limit', // required
    'target'    => '192.168.88.0/24',
    'max-limit' => '10M/10M',      // upload/download, RouterOS's own raw string form
]);
$queue->update($q->id, ['max-limit' => '5M/5M']);
$queue->disable($q->id);
$queue->remove($q->id);
```

`maxLimit`/`priority` are kept as RouterOS's own raw string (e.g.
`"10M/10M"`) rather than parsed — this package does not invent a
split/parse convention RouterOS's documentation doesn't specify.

### 7g. IP Pool management (P19)

`$router->ip()->pools()` maps to RouterOS's `/ip/pool` menu:

```php
$pools = $router->ip()->pools();

$pools->list();                          // Collection<DTO\IpPool>
$pool = $pools->add(['name' => 'dhcp-pool-2', 'ranges' => '192.168.89.10-192.168.89.100']);
$pools->update($pool->id, ['ranges' => '192.168.89.10-192.168.89.150']);
$pools->remove($pool->id);
```

`list()`/`find()`/`add()`/`update()`/`remove()` only — **no `enable()`/
`disable()`**: confirmed directly against a real device that RouterOS's
`/ip/pool` has no `disabled` property in its own model at all. This
package does not force an operation onto a resource that doesn't
support it.

### 7h. IP Route management (P20)

`$router->ip()->routes()` maps to RouterOS's `/ip/route` menu — full
`list()`/`find()`/`add()`/`update()`/`remove()`/`enable()`/`disable()`:

```php
$routes = $router->ip()->routes();

$routes->list();                          // Collection<DTO\IpRoute>
$route = $routes->add(['dst-address' => '10.10.10.0/24', 'gateway' => '192.168.88.1']);
$routes->update($route->id, ['comment' => 'branch link']);
$routes->remove($route->id);
```

**This is this package's highest-risk write resource.** An incorrect
write against `/ip/route` can affect your router's default gateway or
reachability. This package validates only what it can safely guarantee
(a safe identifier, `dst-address` presence) before sending a
request — it does not, and cannot, know which route on your router is
"the important one." **Never modify your default route, a WAN route, or
any route you depend on.** This package's own real-device testing used
only a fully isolated route (a documentation-only `dst-address`,
pointed at an idle, unassigned interface as the gateway) and
independently re-verified the router's actual default route was
untouched afterward.

### 7i. DNS + System identity (P21)

Two **singleton settings** resources — architecturally different from
every module above: there is no `.id`, no list, no `add()`/`remove()`,
just `get()`/`update()`:

```php
$dns = $router->dns()->get();          // /ip/dns
echo $dns->servers;                    // e.g. "8.8.8.8,1.1.1.1"
$router->dns()->update(['servers' => '1.1.1.1,9.9.9.9']);

$identity = $router->systemIdentity()->get(); // /system/identity
echo $identity->name;                          // e.g. "MyRouter"
$router->systemIdentity()->update('NewRouterName');
```

**A real finding from this package's own real-device testing, worth
knowing if you build anything similar yourself**: the obvious guess for
updating a singleton menu — `PATCH` to the menu's own path, with no
identifier — is **wrong**. RouterOS rejects it with `"missing or
invalid resource identifier"` (confirmed directly against a real
device; no change was applied). The correct form is `POST` to the
menu's `/set` path (RouterOS's own `set` console command, run through
REST's documented "POST runs a console command" mechanism) — and that
POST itself returns an **empty body** on success, not the updated
record. Both `update()` methods here POST the write, then transparently
re-`get()` and return the fresh state — you don't need to do this
yourself.

**Real-device verification differs between the two**: `systemIdentity()`
was fully verified (temporary rename → verified → restored to the
original value → verified) since a router's identity/hostname is purely
cosmetic and cannot affect connectivity. `dns()->get()` was verified;
`dns()->update()` was **not** write-tested against a real device —
changing DNS servers is a functionally significant change to a live
router, not a harmless one, so this package's own testing deliberately
stopped at read-only for this specific menu. If you use
`dns()->update()`, treat the underlying `POST .../set` mechanism as
confirmed (via `systemIdentity()`) but the specific `/ip/dns` behavior
as your own responsibility to verify first, on a router you can afford
to misconfigure.

## 8. Monitoring

Everything in this section is **opt-in** — an application that never
publishes/runs these migrations is completely unaffected by any of it.

Publish and run the monitoring migrations:

```bash
php artisan vendor:publish --tag=mikrotik-migrations
php artisan migrate
```

This creates four tables: `mikrotik_snapshots`, `mikrotik_rules`,
`mikrotik_alerts`, `mikrotik_incidents` — all in **your own application's
database**, using your normal Eloquent connection and access controls.
None of them store router credentials.

Record a snapshot (a point-in-time capture of `resource()`/`health()`/
`interfaces()`, stored as JSON columns — RouterOS's already-normalized
output is stored as-is, never re-interpreted):

```php
use Easybdit\LaravelMikrotik\Monitoring\SnapshotRecorder;

$snapshot = app(SnapshotRecorder::class)->record();          // default connection
$snapshot = app(SnapshotRecorder::class)->record('branch-01'); // a specific one

$snapshot->resource;    // array, same shape as RouterResource::toArray()
$snapshot->health;      // array keyed by sensor name
$snapshot->interfaces;  // array keyed by interface name
```

**P24 performance note**: `record()` fetches `resource()`/`health()`/
`interfaces()` **concurrently** (via `RouterConnection::pollAll()`),
not sequentially — measured directly against a real device: ~440-565ms
concurrent vs. ~1000-1250ms sequential for the same three reads
(roughly half the latency, bounded by whichever of the three is
slowest). `record()`'s own behavior/return value is unchanged by this —
it is purely an internal performance improvement, and it fully respects
your connection's own `retry.times`/`retry.sleep` configuration (see
[Basic Configuration](#4-basic-configuration)) if you have one set.

Then, to tie snapshot-recording and rule-evaluation together on a
schedule, run (or schedule) the artisan command:

```bash
php artisan mikrotik:monitor                          # every configured connection
php artisan mikrotik:monitor --connection=branch-01    # a specific one (repeatable)
```

`mikrotik:monitor` does, for each connection: record a snapshot →
evaluate every enabled rule that applies to it → reconcile incidents →
deliver any configured notifications for a newly triggered/resolved
alert. A failure on one connection (unreachable router, rejected
credentials, a RouterOS error, a database error) is reported via a
console error line and does **not** stop the others from being
processed — this is what makes running it repeatedly/on a schedule
safe. This package does **not** register its own schedule entry —
add it to Laravel's own scheduler yourself:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('mikrotik:monitor')->everyFiveMinutes()->withoutOverlapping();
```

`->withoutOverlapping()` is Laravel's own cache-based lock; this package
deliberately does not build a second one — see
[Concurrency](#concurrency) below for what protects you if it still
overlaps.

## 9. Rules

A rule is a plain Eloquent row you create yourself — there is no
config-file or artisan-command way to define one:

```php
use Easybdit\LaravelMikrotik\Models\MikrotikRule;

MikrotikRule::query()->create([
    'connection' => 'branch-01', // or null to apply to every connection
    'name'       => 'High CPU load',
    'metric'     => 'resource.cpu_load',
    'operator'   => '>',           // one of: > >= < <= == !=
    'threshold'  => 90,
    'enabled'    => true,          // default
]);
```

- **`metric`** is a dot path into a recorded snapshot's stored data. The
  first segment must be `resource`, `health`, or `interfaces` (matching
  the three JSON columns on `mikrotik_snapshots`) — e.g.
  `resource.cpu_load`, `health.cpu-temperature.value`,
  `interfaces.ether1.rx_error`. This package does **not** invent metric
  names beyond what `resource()`/`health()`/`interfaces()` already
  expose — see their `toArray()` methods for the exact field names
  available under each prefix.
- **`operator`** must be one of `>`, `>=`, `<`, `<=`, `==`, `!=`. Both
  this and an unsupported `metric` prefix throw `InvalidRuleException`
  **immediately when the rule is saved**, not later when it happens to
  be evaluated.
- **Global vs. connection-specific:** `connection = null` applies the
  rule to every connection; set it to a specific connection name to
  scope it to just that one.
- **`enabled`**: a disabled rule is simply skipped by evaluation.
- **Missing metrics:** a metric absent from a given snapshot (a sensor
  this device doesn't expose, an interface not present) is silently
  skipped — not treated as a match, and does **not** resolve an existing
  active alert.
- **Deduplication:** a rule that matches again while it already has an
  active (`'triggered'`) alert does not create a duplicate row.
- **Resolution:** a rule that stops matching while it has an active
  alert flips that alert to `'resolved'` (`resolved_at` set) — the row
  is never deleted, so history is preserved. If the same rule matches
  again later, a **new, separate** alert row is created (the old one is
  never reopened).

```php
use Easybdit\LaravelMikrotik\Monitoring\RuleEvaluator;

$alerts = app(RuleEvaluator::class)->evaluate($snapshot); // list<MikrotikAlert>, newly-triggered only
```

`evaluate()` only returns *newly-triggered* alerts in its return value —
a resolution or a deduplicated repeat still updates the database but
produces no entry in the returned array. Query
`MikrotikAlert::query()->active()` / `->resolved()` for either state.

### Concurrency

Two `mikrotik:monitor` runs evaluating the same rule+connection at
nearly the same time cannot both create an active alert or an open
incident for it — both `mikrotik_alerts` and `mikrotik_incidents` carry
a real **database unique index**, not an application-level check with a
race window. The losing insert fails with a genuine integrity-constraint
error, which is caught and treated as "the other process already did
this," never as an error and never as a duplicate row. This is not a
claim of distributed locking — prefer `->withoutOverlapping()` on the
scheduler to avoid the overlap in the first place; this is the safety
net for when it still happens.

### Incidents

An alert (above) is one row per continuous triggered→resolved episode
of one rule. An **incident** wraps that same episode in a longer-lived,
stable record. In the current implementation, one incident maps
one-to-one onto one alert episode (a rule's own deduplication already
guarantees at most one active alert at a time, so there is nothing yet
to group across *multiple* alert rows):

```php
use Easybdit\LaravelMikrotik\Monitoring\IncidentManager;

$transitions = app(IncidentManager::class)->reconcile($triggeredAlerts, $justResolvedAlerts);
$transitions['opened'];   // list<MikrotikIncident> newly opened this call
$transitions['resolved']; // list<MikrotikIncident> newly resolved this call
```

`mikrotik:monitor` already calls this for you every run. Cross-rule
correlation (relating incidents from *different* rules that might share
a root cause) is not implemented — see [Feature matrix](#18-feature-matrix).

## 10. Notifications

Optional mail/webhook delivery when `mikrotik:monitor` observes an alert
newly become `'triggered'` or `'resolved'`. **Both channels are disabled
by default:**

```env
MIKROTIK_NOTIFY_MAIL_ENABLED=true
MIKROTIK_NOTIFY_MAIL_TO=ops@example.com

MIKROTIK_NOTIFY_WEBHOOK_ENABLED=true
MIKROTIK_NOTIFY_WEBHOOK_URL=https://example.com/hooks/mikrotik
```

With neither variable set, `AlertNotifier::notify()` is a silent no-op —
Laravel's `Notification` facade is never touched. You can also call it
directly for your own alerts:

```php
use Easybdit\LaravelMikrotik\Monitoring\AlertNotifier;

app(AlertNotifier::class)->notify($alert); // $alert: MikrotikAlert
```

**Security notes:** delivery uses Laravel's own on-demand/anonymous
notifiable — this package has no "user" model to notify, and
`config('mikrotik.notifications')` holds only a destination address/URL,
never router credentials. The webhook payload carries only fields
`mikrotik_alerts` already stores (`id`, `status`, `connection`, `metric`,
`operator`, `threshold`, `value`, `message`, `triggered_at`,
`resolved_at`) — never credentials. Delivery is synchronous (no
queueing); queue it yourself if you need that.

## 11. Analytics

`MonitoringAnalytics` is entirely read-only history/trend queries over
data P4/P5/P10 already store — **it makes no RouterOS requests of its
own** and never writes to any table:

```php
use Easybdit\LaravelMikrotik\Monitoring\MonitoringAnalytics;

$analytics = app(MonitoringAnalytics::class);

$analytics->resourceTrend('branch-01', 'cpu_load', since: now()->subDay());
// list<array{captured_at: Carbon, value: mixed}>

$analytics->healthTrend('branch-01', 'cpu-temperature');
// same shape, over one health sensor's ->value

$analytics->interfaceCounterTrend('branch-01', 'ether1', 'rx_error');
// same shape, over one interface counter (rx_error/tx_error/rx_drop/tx_drop/etc.)

$analytics->interfaceRateHistory('branch-01', 'ether1', 'rx_byte');
// list<array{from: Carbon, to: Carbon, rate_per_second: float|null, counter_reset: bool}>
// computed between each pair of consecutive stored snapshots — NOT a live
// RouterOS reading (that's interfaceRate() from section 5).
// counter_reset=true (rate_per_second=null) instead of a misleading
// negative number when a reboot reset the counter between snapshots.

$analytics->alertHistory('branch-01');            // Collection<MikrotikAlert>, any status
$analytics->alertCountsByRuleMetric('branch-01'); // list<array{rule_id, metric, count}>

$analytics->openIncidents('branch-01');              // Collection<MikrotikIncident>, ongoing now
$analytics->incidentHistory('branch-01');            // Collection<MikrotikIncident>, any status
$analytics->incidentCountsByRule('branch-01');       // list<array{rule_id, count}>
$analytics->averageIncidentDurationSeconds('branch-01'); // float|null — resolved incidents only
```

Every method accepts optional `?Carbon $since = null, ?Carbon $until =
null` to bound the window. Every query filters first on the already-
indexed `connection` + `captured_at`/`triggered_at`/`opened_at` columns
before touching a row's JSON columns.

## 12. Real MikroTik Testing Guide

`Http::fake()` tests (see [section 13](#13-testing-without-a-real-router))
prove this package sends the request RouterOS's own documentation says
it should, and handles the response shape that documentation shows. They
do **not** prove your specific router, RouterOS version, or user
permissions actually behave that way. This section is how to verify that
safely, without risking anything in your network.

### Step 1 — Prepare the router

- Create a **dedicated test RouterOS user** rather than reusing `admin`
  (Winbox/WebFig → **System → Users**). Never share this (or any)
  password with anyone, including in a bug report to this package.
- Give it only the policy it needs: **`read`** to try the read
  capabilities in [section 1](#1-what-this-package-is); additionally
  **`write`** only if you intend to try `ip()->addresses()` or
  `interface()`. Don't grant more than that.
- Confirm **IP → Services → `www-ssl`** is enabled. If your router
  restricts which IPs may reach a service, make sure the machine running
  your Laravel app is allowed.

### Step 2 — Configure Laravel

```env
MIKROTIK_HOST=<your-router-ip>
MIKROTIK_PORT=443
MIKROTIK_USERNAME=<your-dedicated-test-user>
MIKROTIK_PASSWORD=<its-password>
MIKROTIK_VERIFY_TLS=true
```

Use placeholders like these in any config you share — never a real
value.

### Step 3 — Verify read access

Before trying anything else, confirm the basics work:

```php
$router = Mikrotik::connection();

$router->resource();               // should return version/board info
$router->health();                 // should return whatever sensors your device has
$router->interfaces();             // should list your device's interfaces
$router->ip()->addresses()->list(); // should list your device's configured addresses
```

If any of these throw, see [Troubleshooting](#15-troubleshooting) before
going further — do not proceed to a write test until reads succeed.

### Step 4 — Safe write test

**Only attempt this against a resource you are certain is safe to
change**, and only if you actually need to verify write behavior for
your use case — most applications only need the read capabilities above.

Pick a **dedicated, clearly unused** test address or a **clearly idle**
interface (no active link, no production traffic, no IP already
assigned) — never a production IP, route, or an interface actually
carrying traffic. Use a documentation-only address for the value itself:
`203.0.113.99/32` ([RFC 5737](https://www.rfc-editor.org/rfc/rfc5737))
is reserved for exactly this purpose and is never a real, routable
address.

Exact lifecycle, confirmed against a real device by this package's own
test process (RouterOS 7.10.2, RB3011UiAS):

```php
$addresses = $router->ip()->addresses();

// 1. List existing addresses.
$before = $addresses->list();

// 2. Confirm no collision with your chosen test value.
foreach ($before as $a) {
    if ($a->address === '203.0.113.99/32') {
        throw new RuntimeException('Already exists — pick a different test value.');
    }
}

// 3. Add.
$created = $addresses->add(['address' => '203.0.113.99/32', 'interface' => 'your-idle-interface']);

// 4. Find/list to confirm it.
$found = $addresses->find($created->id);

// 5. Update a harmless field.
$addresses->update($created->id, ['comment' => 'temporary-test-delete-me']);

// 6. Verify the update.
$addresses->find($created->id)->comment; // should read back what you set

// 7. Disable.
$addresses->disable($created->id);

// 8. Verify it is actually disabled.
$addresses->find($created->id)->disabled; // should be true

// 9. Enable.
$addresses->enable($created->id);

// 10. Verify it is actually enabled.
$addresses->find($created->id)->disabled; // should be false

// 11. Remove.
$addresses->remove($created->id);

// 12. Verify it is gone.
$addresses->list()->contains(fn ($a) => $a->address === '203.0.113.99/32'); // should be false
```

**Stop immediately** if any step behaves unexpectedly, or if you are not
completely certain the resource you selected is safe — do not guess, and
do not continue "to see what happens." A failed or interrupted test
still needs step 11 (remove) attempted as cleanup — see Step 5.

### Step 5 — Verify cleanup

After any write test (successful or not), always re-run `list()` (or
`find()`) and confirm no temporary test data remains. If a cleanup step
failed partway through, remove the leftover test entry manually before
considering the test finished.

### Step 6 — Never test by changing

Never use this package's write capabilities to change, on a router you
cannot afford to misconfigure:

- Production IP addresses
- Routes
- Firewall rules
- DHCP configuration
- PPP/PPPoE configuration
- Queues
- WAN interfaces, or any interface actually carrying production traffic

...unless you are working against a dedicated lab router with no
production role at all.

## 13. Testing Without a Real Router

```bash
composer install
vendor/bin/phpunit
```

The test suite uses `Illuminate\Support\Facades\Http::fake()`
throughout — **no physical MikroTik router is required to run it.**
`Http::fake()` intercepts every outgoing HTTP request this package would
make and returns a canned response you specify instead, letting tests
assert exactly what request was sent (method, URL, body) without a
network call ever happening. The base `TestCase` also calls
`Http::preventStrayRequests()`, so any request that doesn't match a
registered fake fails the test immediately instead of silently making a
real network call.

CI (`.github/workflows/tests.yml`) runs this suite against every
PHP/Laravel combination in [Requirements](#2-requirements) on SQLite,
plus one additional run against a real MySQL 8 service container — the
monitoring migrations are written to be portable across both.

**`Http::fake()` tests are not equivalent to real-device verification.**
They prove this package builds the request RouterOS's documentation says
it should, and correctly parses the response shape that documentation
shows — they cannot prove your specific router version or configuration
actually behaves that way. See
[Real MikroTik Testing Guide](#12-real-mikrotik-testing-guide) above for
how this package itself has been verified against a real device, and how
you can verify your own.

## 14. Production Testing Checklist

Before relying on this package against a production router:

- [ ] RouterOS reachable from the machine running your Laravel app
- [ ] HTTPS REST (`www-ssl`) service enabled on the router
- [ ] Correct `MIKROTIK_USERNAME` configured
- [ ] That user has the correct RouterOS permissions (`read`, plus
      `write` only if you use `ip()->addresses()`/`interface()`)
- [ ] `MIKROTIK_VERIFY_TLS` configuration checked and understood (stay
      `true` unless you have a specific, understood reason not to)
- [ ] A basic read (`resource()`/`health()`/`interfaces()`) tested
      successfully against the real router
- [ ] If you need write capabilities: a safe, clearly-unused test
      resource selected (never a production one)
- [ ] The write test lifecycle completed successfully
- [ ] The temporary test resource confirmed removed
- [ ] `.env`/credentials confirmed **not** committed to version control
- [ ] `vendor/bin/phpunit` passes in your own application's environment

## 15. Troubleshooting

| Exception | Meaning | Common cause |
|---|---|---|
| `ConnectionException` | The router could not be reached at all. | Wrong host/port, router offline, firewall blocking the connection, or the request timed out (`->timedOut()` — check `MIKROTIK_TIMEOUT`). |
| `AuthenticationException` | The router was reached, but rejected the credentials (HTTP 401/403). | Wrong `MIKROTIK_USERNAME`/`MIKROTIK_PASSWORD`, or the RouterOS user is disabled. |
| `RouterOsException` | The router was reached, credentials were fine, but RouterOS itself returned an error for the request. | An invalid value in a write request, a missing required field RouterOS itself requires, a "not found" `.id`, or — commonly for a write — insufficient RouterOS user permissions (`"not enough permissions (9)"` in `$e->getRouterOsDetail()` means the user needs the `write` policy). `$e->getRouterOsErrorCode()`/`$e->getRouterOsDetail()` give RouterOS's own error code/detail. |
| `MalformedResponseException` | The router returned a 2xx response, but the body wasn't valid/expected JSON. | Usually indicates a RouterOS version difference this package hasn't seen — please report it (without any credentials in the report). |
| `InvalidConfigurationException` | A problem in *this package's own* configuration, not the router. | An unknown connection name, a connection missing `host`/`username`/`password`, or an unsupported `transport` value (only `'rest'` exists today). |
| `InvalidRuleException` | A `MikrotikRule` you tried to save is malformed. | An `operator` outside `>`, `>=`, `<`, `<=`, `==`, `!=`, or a `metric` not starting with `resource.`, `health.`, or `interfaces.`. |
| `InvalidMenuPathException` | A `menu()` path or item identifier failed this package's own safety validation, **before any request was sent**. | A path/identifier containing `..`, a full URL, or a character outside the safe allow-list. |
| `InvalidResourceException` | A typed write call (`ip()->addresses()->add()`) is missing a field this package requires before it will even send a request. | Calling `add()` without `address`. |

**A note on TLS errors** specifically: they surface as
`ConnectionException` (the router was not successfully reached over
HTTPS) — check `MIKROTIK_VERIFY_TLS` and whether the router's
certificate is trusted, or self-signed and not yet imported into your
trust store.

No exception this package throws ever includes the configured username
or password — see [Security](#16-security).

## 16. Security

- Credentials stay in your application's own `.env`/config — this
  package adds no database table or admin UI for them.
- **Never commit `.env`** to version control.
- **Never print, log, or paste a password** — including into a bug
  report or this package's own tests/documentation. No exception message
  this package throws ever includes the configured username or password
  (verified by the test suite), and no request this package makes is
  ever logged.
- **Every write in this package is non-retrying** — `put()`/`patch()`/
  `delete()`/`postWrite()` (the internal transport methods every typed
  write resource is built on) never use the read-path retry
  configuration, regardless of how it's set. An ambiguous failure after
  a write already reached RouterOS is never automatically retried, since
  that could risk a duplicate `add()` or a reapplied `update()`.
- Every menu path and item identifier (`menu()`, and every typed write
  resource: `ip()->addresses()`, `interface()`, `firewall()->filter()`,
  `dhcp()->servers()`/`leases()`, `ppp()->secrets()`, `queue()->simple()`,
  `ip()->pools()`/`routes()`) is validated against a strict allow-list
  before a request is ever built — this is what stops a path-traversal
  or protocol-like value from ever reaching the router.
- **PPP secret passwords are handled specially** — see
  [PPP/PPPoE secret management](#7e-ppppppoe-secret-management-p17).
  RouterOS itself returns a secret's password in plain text; this package strips
  it before it ever reaches your code, everywhere except a request body
  you send yourself.
- Always use HTTPS (there is no alternative in this package) and keep
  TLS certificate verification enabled in production.
- Use a **least-privilege** RouterOS user: `read`-only unless you
  specifically need a write capability (see
  [Requirements](#2-requirements) for the full list), in which case add
  `write` — never hand this package `full`/`admin` access by default.
- `ip()->routes()` carries real risk if misused against a production
  router (it can affect reachability) — see
  [IP Route management](#7h-ip-route-management-p20) before using it.
- Monitoring's database tables (`mikrotik_snapshots`, `mikrotik_rules`,
  `mikrotik_alerts`, `mikrotik_incidents`) store only what `resource()`/
  `health()`/`interfaces()` already return plus rule/alert bookkeeping —
  never router credentials.

## 17. Architecture Overview

```
Your Laravel application
        |
        v
Mikrotik facade  ->  ConnectionManager  ->  RouterConnection
        |                                        |
        |                                        v
        |                          Typed RouterOS APIs (resource(),
        |                          health(), interfaces(), logs(),
        |                          menu(), ip(), interface(),
        |                          firewall(), dhcp(), ppp(), queue(),
        |                          dns(), systemIdentity())
        |                                        |
        |                                        v
        |                          ResponseNormalizer (raw JSON -> DTOs)
        |                                        |
        |                                        v
        |                                   Transport (RestTransport)
        |                                        |
        |                                        v
        |                          RouterOS REST API (HTTPS)

Monitoring (all opt-in, built on the above):
   mikrotik:monitor
        -> SnapshotRecorder  (calls RouterConnection::pollAll(), P24 --
                               resource()/health()/interfaces() concurrently)
        -> RuleEvaluator     (reads MikrotikRule rows, writes MikrotikAlert rows)
        -> IncidentManager   (writes MikrotikIncident rows)
        -> AlertNotifier     (mail/webhook, optional)
   MonitoringAnalytics reads everything the above already stored.
```

The pieces you'll actually interact with:

- **`ConnectionManager`** (`Mikrotik::connection('name')`) resolves and
  caches a named connection from `config('mikrotik.connections')` — the
  same idiom Laravel's own `config('database.connections')` uses.
- **`RouterConnection`** is the object you get back — it's the main
  public API surface of this package; you should rarely need to touch
  anything below it directly.
- **`Menu`** is the class behind `menu()` — generic, read-only, path-
  validated access to any RouterOS menu.
- **`RestTransport`** is the internal HTTP layer (Laravel's own `Http`
  client under the hood) — it's what actually issues `GET`/`POST`/`PUT`/
  `PATCH`/`DELETE` requests and turns a non-2xx response or a connection
  failure into the right exception type. `postWrite()` (P21) is a
  non-retrying `POST`, used only by the two singleton settings resources
  (`dns()`/`systemIdentity()`) whose `set` command is invoked via `POST`
  rather than `PATCH` — see [section 7i](#7i-dns--system-identity-p21).
  `getMany()` (P24) fetches several reads concurrently via
  `Http::pool()`, used by `RouterConnection::pollAll()`. You should not
  need to use `RestTransport` directly.
- **DTOs** (`RouterResource`, `HealthReading`, `InterfaceCollection`,
  `IpAddress`, `InterfaceRecord`, `FirewallFilterRule`, `DhcpServer`,
  `DhcpLease`, `PppSecret`, `SimpleQueue`, `IpPool`, `IpRoute`,
  `DnsSettings`, `SystemIdentity`, etc.) are the typed objects every
  method returns — see [section 5](#5-first-connection) for why every
  field on them is nullable.
- **Monitoring services** (`SnapshotRecorder`, `RuleEvaluator`,
  `IncidentManager`, `AlertNotifier`, `MonitoringAnalytics`) are plain
  Laravel-container-resolvable classes (`app(ClassName::class)`), each
  documented in its own section above.

## 18. Feature Matrix

| Feature | Status | Read | Write | Notes |
|---|---|---|---|---|
| `resource()` — system resource | Done | Yes | — | `/system/resource` |
| `health()` — hardware sensors | Done | Yes | — | `/system/health`; sensor set varies per board |
| `interfaces()` — interfaces + counters | Done | Yes | — | `/interface`; cumulative counters, not live |
| `interfaceRate()` — one-shot live rate | Done | Yes | — | `/interface/monitor-traffic ... once` |
| `logs()` — router logs | Done | Yes | — | `/log`; simple equality filtering only |
| `menu()` — generic menu access | Done (P9) | Yes | No (deliberate) | Any other RouterOS menu, raw untyped rows, read-only forever |
| `menu()->query()` — advanced query/proplist | Done (P23) | Yes | No | `.query`/`.proplist` POST mechanism; only equality + `"#|"` (OR) confirmed; real-device verified across 2 menus |
| `ip()->addresses()` — IP address mgmt | Done (P13) | Yes | Yes | `list/find/add/update/remove/enable/disable`; real-device verified |
| `interface()` — interface mgmt | Done (P14) | Yes | Yes (no add/remove) | `list/find/update/enable/disable`; real-device verified |
| `firewall()->filter()` — firewall filter mgmt | Done (P15) | Yes | Yes | Full CRUD + enable/disable; real-device verified |
| `dhcp()->servers()` / `->leases()` — DHCP mgmt | Done (P16) | Yes | Yes | Full CRUD + enable/disable on both; real-device verified |
| `ppp()->secrets()` — PPP/PPPoE secret mgmt | Done (P17) | Yes | Yes | Full CRUD + enable/disable; passwords never exposed; real-device verified |
| `queue()->simple()` — simple queue mgmt | Done (P18) | Yes | Yes | Full CRUD + enable/disable; real-device verified |
| `ip()->pools()` — IP pool mgmt | Done (P19) | Yes | Yes (no enable/disable) | `list/find/add/update/remove`; real-device verified |
| `ip()->routes()` — IP route mgmt | Done (P20) | Yes | Yes | Full CRUD + enable/disable; highest-risk resource; real-device verified (isolated test route only) |
| `dns()` — DNS settings | Done (P21) | Yes | Yes (unverified on real device) | Singleton settings menu; `get()` real-device verified, `update()` not write-tested |
| `systemIdentity()` — system identity | Done (P21) | Yes | Yes | Singleton settings menu; `get()`/`update()` real-device verified |
| Write transport (`put/patch/delete/postWrite`) | Done (P12/P21) | — | Internal only | Not directly exposed; foundation for every typed write resource above |
| Opt-in retry (reads only) | Done (P9) | — | N/A | Never retries a write; never retries 401/403/4xx |
| Monitoring snapshots | Done (P4) | Local DB | Local DB only | Stores `resource()`/`health()`/`interfaces()` output; no RouterOS writes |
| Rules + alert lifecycle | Done (P5) | Local DB | Local DB only | Triggered/resolved lifecycle, deduplicated |
| Incidents | Done (P10) | Local DB | Local DB only | One incident per one alert episode (no cross-episode grouping yet) |
| `mikrotik:monitor` command | Done (P6) | Yes (via above) | Local DB only | Not self-scheduling — add to Laravel's scheduler |
| Notifications (mail/webhook) | Done (P7) | — | External (opt-in) | Disabled by default; synchronous |
| Analytics (`MonitoringAnalytics`) | Done (P8/P10) | Local DB only | — | No RouterOS requests of its own |
| Production hardening (CI, concurrency, security) | Done (P11) | — | — | See [Security](#16-security), [Concurrency](#concurrency) |
| `RouterConnection::pollAll()` — concurrent reads | Done (P24) | Yes | — | `resource()+health()+interfaces()` via `Http::pool()`; ~2x faster, real-device measured |
| Final security/API/release audit | Done (P25) | — | — | Full repo security sweep, API consistency review, final real-device sweep |
| User / Scheduler management | **Planned** | No | No | P22 — deliberately not yet implemented; see [Roadmap](#19-roadmap) |
| Binary RouterOS API (port 8728/8729) | **Not planned** | No | No | REST only |
| Multi-tenant / SaaS features | **Not planned** | — | — | — |

## 19. Roadmap

**P1–P21 are complete, and P23–P25 are complete** (see
[CHANGELOG.md](CHANGELOG.md) for full, phase-by-phase history) —
including firewall filter, DHCP, PPP/PPPoE secret, simple queue, IP
pool, IP route, and DNS/system identity management (all real-device
verified except `dns()->update()` — see
[section 7i](#7i-dns--system-identity-p21)), advanced `menu()->query()`
filtering, concurrent reads (`pollAll()`), and a final production
security/API audit. This package is considered stable as of this
release (see [License](#license) for the tag/version).

**P22 — User/Scheduler management was deliberately skipped** in this
cycle in favor of P23-P25's query/performance/release-hardening work,
and remains planned:

- **Planned — P22: User / Scheduler management** (`/user`, `/system/scheduler`)

Beyond P22, nothing further is currently planned. Any future addition
will still be scoped against RouterOS's official REST documentation and
a real device first, per this package's standing rule never to guess
RouterOS behavior.

## Known limitations

This package intentionally does **not** include:

- Continuous/streaming traffic monitoring — `interfaces()` gives
  cumulative counters and `interfaceRate()` gives a one-shot reading, but
  nothing subscribes to a live feed (RouterOS REST has no supported way
  to do this at all)
- Log filtering beyond simple equality (`?field=value`) — no `~`
  (contains/regex) filtering, no pagination beyond RouterOS's own log
  buffer size
- VPN modules beyond PPP/PPPoE secrets (no IPsec/WireGuard/OpenVPN/L2TP
  management), wireless, bridge/VLAN, or user/scheduler management (see
  [Roadmap](#19-roadmap))
- A built-in schedule entry or locking for `mikrotik:monitor` — you
  register it (and `->withoutOverlapping()`) on Laravel's own scheduler
  yourself
- Any config-file or artisan-command way to define a `MikrotikRule` —
  create one directly via Eloquent
- Cross-rule incident correlation, or grouping *multiple* alert episodes
  of the same rule into one incident (e.g. brief flapping) — one
  incident always maps to exactly one alert episode
- Any framework event (`Illuminate\Events`) dispatched when a snapshot is
  recorded or an alert/incident changes state — only the explicit P7
  mail/webhook notifications exist
- Notification channels beyond mail/webhook, and no queueing of
  notification delivery (synchronous by design)
- Any RouterOS configuration menu beyond the ones listed in the
  [Feature matrix](#18-feature-matrix) — `menu()` remains read-only for
  everything else
- `add()`/`remove()` for interfaces, or any interface subtype menu
  (`/interface/vlan`, `/interface/bridge`, etc.)
- `add()`/`remove()` for `dns()`/`systemIdentity()` — both are singleton
  settings menus RouterOS itself has no concept of "adding"/"removing"
- `enable()`/`disable()` for `ip()->pools()` — RouterOS's `/ip/pool` has
  no `disabled` property
- Real-device write verification for `dns()->update()` specifically —
  deliberately not tested against a real device (changing DNS servers
  is functionally significant, not harmless); see
  [section 7i](#7i-dns--system-identity-p21)
- Retry of any kind for write operations
- Filtering beyond simple equality on `menu()->get()` — `menu()->query()`
  (P23) adds `.query`/`.proplist`, but only equality conditions and the
  `"#|"` (OR) stack operator are confirmed; comparison operators (`>`,
  `<`), `NOT`, and `~` (contains/regex) are not confirmed and are not
  validated
- A confirmed not-found behavior for `menu()->find()` on a `GET` — it
  surfaces RouterOS's generic error response rather than returning
  `null`, since the exact "not found" error shape for `GET` (as opposed
  to `DELETE`, which is confirmed) was not independently verified
- Concurrent/batched *write* requests, or concurrent reads *across
  multiple connections/routers* — `pollAll()` (P24) concurrently fetches
  three reads *for one connection*; `mikrotik:monitor` still processes
  multiple configured connections one at a time, to preserve its
  existing per-connection error isolation (one bad router doesn't stop
  the others)
- Distributed locking — the database unique-index protection in
  [Concurrency](#concurrency) is real database-level protection, not a
  distributed lock
- A binary RouterOS API transport (port 8728/8729) — REST only
- Any AI integration — a separate, not-yet-built package, no dependency
  on it from this one
- Multi-tenant / SaaS functionality
- Database-backed router credentials — `.env`/config file based only
- User/scheduler management (P22) — deliberately not yet implemented;
  see [Roadmap](#19-roadmap)

### RouterOS permissions

`interfaces()` and `logs()` are expected to work with the same `read`
policy `resource()`/`health()` already require, but this was not
independently re-confirmed for those two specifically against a live
device — if your RouterOS user has a restricted policy set, verify read
access to these menus yourself. `menu()` is a generic accessor: whatever
`read` policy your user needs for a given menu is entirely between your
RouterOS user configuration and that menu.

Every typed write resource (`ip()->addresses()`, `interface()`,
`firewall()->filter()`, `dhcp()->servers()`/`leases()`,
`ppp()->secrets()`, `queue()->simple()`, `ip()->pools()`/`routes()`,
`systemIdentity()`) additionally requires the `write` policy —
confirmed directly against a real device (see
[Requirements](#2-requirements)).

## Production recommendations

A short checklist beyond the per-topic guidance already in this README:

- **Least privilege first.** Give the RouterOS user this package
  connects as only `read`, and add `write` only if your application
  actually calls one of the typed write resources — see
  [RouterOS permissions](#routeros-permissions) above.
- **Keep `MIKROTIK_VERIFY_TLS=true`** unless you have a specific,
  understood reason not to — see [Requirements](#2-requirements).
- **Set a realistic `MIKROTIK_TIMEOUT`** for your network — this
  package clamps it to 1-120s, but the right value within that range
  depends on your own router's typical response time (this package's
  own real-device measurements: ~270-680ms per individual read call).
- **Enable retry (`MIKROTIK_RETRY_TIMES`) only for reads you can afford
  to repeat** — it never applies to a write, by design (see
  [Security](#16-security)), and it fully applies to `menu()->query()`
  and `pollAll()` (P23/P24) the same way it already applies to every
  other read.
- **Schedule `mikrotik:monitor` with `->withoutOverlapping()`** (see
  [Monitoring](#8-monitoring)) rather than running it more frequently
  than a run typically completes.
- **Treat `ip()->routes()` and `ppp()->secrets()` with extra care** —
  see their own sections
  ([7h](#7h-ip-route-management-p20), [7e](#7e-ppppppoe-secret-management-p17))
  before using either in production.
- **Run the [Real MikroTik Testing Guide](#12-real-mikrotik-testing-guide)
  against your own device** before depending on any write capability in
  production — this package's own real-device verification proves its
  implementation is correct against the specific device it was tested
  on, not against yours.
- **Never commit `.env`**, and treat any PPP secret password the same
  way — see [Security](#16-security) and
  [PPP/PPPoE secret management](#7e-ppppppoe-secret-management-p17).

## License

MIT
