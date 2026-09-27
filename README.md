# easybdit/laravel-mikrotik

A Laravel abstraction for MikroTik RouterOS devices.

**This is P1 + P2 + P3 — an intentionally small, incrementally-grown
release.** It provides connection management and read-only retrieval of
router resource info, hardware health, interfaces (with cumulative
traffic counters and a one-shot rate reading), and log entries, all over
the RouterOS REST API. Everything else — DHCP/PPP/firewall/VPN,
monitoring persistence, scheduled polling, alerting, a binary-API
transport, and AI-powered diagnosis (`easybdit/laravel-mikrotik-ai`, a
separate future package) — is **not implemented yet**. See
[Known limitations](#known-limitations) below for the authoritative list.

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13
- A MikroTik router running **RouterOS v7.1beta4 or later**, with the
  `www-ssl` service enabled (Winbox/WebFig → IP → Services → `www-ssl`)

This package is **HTTPS-only by design**, not just by default. RouterOS's
plain-HTTP REST variant sends your router credentials as unencrypted HTTP
Basic Auth, and MikroTik's own documentation advises against it outside
isolated testing. There is no configuration option to use plain HTTP.

## Installation

```bash
composer require easybdit/laravel-mikrotik
```

Publish the config file:

```bash
php artisan vendor:publish --tag=mikrotik-config
```

## Configuration

Set your router's connection details in `.env`:

```env
MIKROTIK_CONNECTION=default
MIKROTIK_HOST=192.168.88.1
MIKROTIK_PORT=443
MIKROTIK_USERNAME=admin
MIKROTIK_PASSWORD=your-password-here
MIKROTIK_VERIFY_TLS=true
MIKROTIK_TIMEOUT=10
```

To monitor more than one router, add additional named entries directly in
`config/mikrotik.php`:

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

`MIKROTIK_VERIFY_TLS` should stay `true` in production. Only set it to
`false` for a router using a self-signed certificate you have not
imported into a trusted CA store, and only if you understand the risk
(disabling TLS verification exposes you to man-in-the-middle attacks
against your router credentials).

## Usage

```php
use Easybdit\LaravelMikrotik\Facades\Mikrotik;

// Default connection
$router = Mikrotik::connection();

// A specific named connection
$router = Mikrotik::connection('branch-01');

$resource = $router->resource();
echo $resource->version;      // "7.15 (stable)"
echo $resource->cpuLoad;      // 3 (int, percent)
echo $resource->freeMemory;   // 1503133696 (int, bytes)

$health = $router->health();

if ($health->has('cpu-temperature')) {
    echo $health->get('cpu-temperature')->value; // 43 (int)
    echo $health->get('cpu-temperature')->type;  // "C"
}

// Devices expose different sensors — iterate whatever is actually there:
foreach ($health as $sensor) {
    echo "{$sensor->name}: {$sensor->value}{$sensor->type}\n";
}
```

### Interfaces and traffic counters

```php
$interfaces = $router->interfaces();

$eth1 = $interfaces->get('ether1');
echo $eth1->type;         // "ether"
echo $eth1->running;      // true (bool)
echo $eth1->rxByte;       // 205164277 (int, cumulative bytes received)
echo $eth1->rxError;      // 0 (int)
echo $eth1->linkDowns;    // 2 (int)

foreach ($interfaces as $interface) {
    echo "{$interface->name}: {$interface->rxByte} rx / {$interface->txByte} tx\n";
}
```

**Traffic counters are cumulative, not live.** `rxByte`/`txByte`/
`rxPacket`/`txPacket`/`rxError`/`txError`/`rxDrop`/`txDrop` are the
totals RouterOS has counted since the interface's counters were last
reset — in practice, since the last reboot. This package does **not**
offer a live/instantaneous throughput reading here — see
[One-shot interface rate](#one-shot-interface-rate) below for that, or
poll `interfaces()` twice and divide the delta by the elapsed time:

```php
$before = $router->interfaces()->get('ether1')->rxByte;
sleep(5);
$after = $router->interfaces()->get('ether1')->rxByte;

$bytesPerSecond = ($after - $before) / 5;
```

Be aware that a reboot resets these counters to zero — a delta taken
across a reboot will read as negative or nonsensical, not as an error;
this package does not detect or guard against that case.

`interfaces()` makes a single RouterOS REST call: `GET /rest/interface`.
An earlier version of this package additionally called `POST
/interface/print` with `{"stats-detail":""}`, on the assumption that
counters needed a separate "stats" view — that's how RouterOS's own
console splits them (`/interface print` vs `/interface print
stats-detail`). Real-device verification (RouterOS 7.10.2, RB3011UiAS)
showed the REST endpoint returns the full field set, counters included,
in one response, so the second call was removed.

### One-shot interface rate

```php
$rate = $router->interfaceRate('ether1');

echo $rate->rxBitsPerSecond;    // 159176 (int)
echo $rate->txBitsPerSecond;    // 227456 (int)
echo $rate->rxPacketsPerSecond; // 182 (int)
```

This is RouterOS's `/interface monitor-traffic ... once` — a single
instantaneous rate reading RouterOS itself computes over a brief
internal sampling window, **not** a live/streaming subscription.
Verified directly against a real device: `POST
/rest/interface/monitor-traffic` with body `{"interface": "ether1",
"once": ""}`. An invalid interface name throws `RouterOsException` with
RouterOS's own message (`"input does not match any value of
interface"`).

Do not confuse this with `interfaces()`'s cumulative counters: this
method makes a fresh request every call and returns whatever RouterOS
measured at that moment, with no history retained by this package.

### Logs

```php
$logs = $router->logs();

foreach ($logs as $entry) {
    echo "{$entry->time} [{$entry->topics}] {$entry->message}\n";
}

// Simple equality filtering (sent as a REST query-string parameter):
$criticalOnly = $router->logs(['topics' => 'critical']);
```

`$entry->time` and `$entry->topics` are preserved exactly as RouterOS
returns them and are **not** parsed. MikroTik's own documentation
confirms the console log timestamp omits the date entirely for today's
entries and omits the year always — there is no single safe format to
normalize into without risking silent misinterpretation. `topicsList()`
offers a best-effort comma-split of `topics` for convenience, but is not
authoritative.

Filtering only supports simple equality (RouterOS REST's documented
`?field=value` query-string form). RouterOS's console-only `~`
(contains/regex) filter operator is not supported over REST filtering in
this phase.

### Why `health()` doesn't return a fixed object

MikroTik hardware exposes different sensors per board — confirmed
directly from MikroTik's documentation: a CCR1072-1G-8S+ reports power
consumption, CPU temperature, four fan speeds, two board temperatures,
and dual PSU voltage/current; other boards report only `voltage` and
`temperature`; some legacy boards report numbered sensors like
`voltage1`..`voltage10`. `health()` therefore returns a `HealthReading`
— a bag of `HealthSensor` objects keyed by RouterOS's own sensor name.
Asking for a sensor a device doesn't have returns `null`; it never
throws.

## Exception handling

```php
use Easybdit\LaravelMikrotik\Exceptions\AuthenticationException;
use Easybdit\LaravelMikrotik\Exceptions\ConnectionException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Easybdit\LaravelMikrotik\Exceptions\MalformedResponseException;
use Easybdit\LaravelMikrotik\Exceptions\MikrotikException; // base type

try {
    $resource = Mikrotik::connection('branch-01')->resource();
} catch (ConnectionException $e) {
    // Router unreachable or the request timed out.
} catch (AuthenticationException $e) {
    // Router reached; credentials were rejected.
} catch (RouterOsException $e) {
    // Router reached, credentials fine, RouterOS itself returned an error.
    // $e->getRouterOsErrorCode() / $e->getRouterOsDetail() are available.
} catch (MalformedResponseException $e) {
    // 2xx response, but not valid/expected JSON.
} catch (MikrotikException $e) {
    // Catch-all for any of the above.
}
```

## Security

- HTTPS only; TLS certificate verification is **on by default**.
- Credentials live in your app's own config/`.env` — this package does
  not add a database table or admin UI for them in P1.
- No exception message this package throws ever includes the configured
  username or password — verified directly by the test suite (see
  `tests/Feature/SecurityTest.php`).
- This package's exception chain never retains the underlying Guzzle
  request object either. A connection failure's root cause is preserved
  as a plain message (host + failure description), not as the original
  exception object — which would otherwise carry the full outgoing
  request, including the `Authorization: Basic ...` header, reachable via
  `getPrevious()`.
- The package never logs a request it makes, and in particular never
  logs the `Authorization` header.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The test suite uses `Illuminate\Support\Facades\Http::fake()` throughout
— no physical MikroTik router is required to run it. The base `TestCase`
also calls `Http::preventStrayRequests()`, so any request that doesn't
match a registered fake fails the test immediately instead of silently
making a real network call.

## Known limitations

This package intentionally does **not** include:

- Continuous/streaming traffic monitoring — `interfaces()` gives
  cumulative counters, and `interfaceRate()` gives a one-shot
  instantaneous reading, but nothing subscribes to a live feed (RouterOS
  REST has no supported way to do this at all — see
  [Interfaces and traffic counters](#interfaces-and-traffic-counters) and
  [One-shot interface rate](#one-shot-interface-rate))
- Log filtering beyond simple equality (`?field=value`) — no `~`
  (contains/regex) filtering, no pagination beyond RouterOS's own log
  buffer size
- DHCP, PPP/PPPoE, firewall, or VPN modules
- Monitoring persistence, scheduled polling, or alerting
- A binary RouterOS API transport (port 8728/8729) — REST only
- Any AI integration (`easybdit/laravel-mikrotik-ai` is a separate,
  not-yet-built package; this package has no dependency on
  `easybdit/laraveleasyai` and never will)
- Multi-tenant / SaaS functionality
- Database-backed router credentials — configuration is `.env`/config
  file based only

### RouterOS permissions

`interfaces()` and `logs()` were verified against MikroTik's public
documentation only (no live device was available for this package's
development) and are expected to work with the same RouterOS user
"read" policy that `resource()`/`health()` already require. This was not
independently re-confirmed for `/interface` or `/log` specifically —
if your RouterOS user has a restricted policy set, verify read access to
these menus against your own device.

## License

MIT
