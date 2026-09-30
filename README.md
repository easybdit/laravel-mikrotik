# easybdit/laravel-mikrotik

A Laravel abstraction for MikroTik RouterOS devices.

**This is P1 + P2 + P3 + P4 + P5 — an intentionally small,
incrementally-grown release.** It provides connection management and
read-only retrieval of router resource info, hardware health, interfaces
(with cumulative traffic counters and a one-shot rate reading), log
entries, opt-in database persistence of point-in-time monitoring
snapshots, and opt-in threshold rules that record an alert when a
snapshot crosses them, all over the RouterOS REST API. Everything else —
DHCP/PPP/firewall/VPN, scheduled polling, a binary-API transport, and
AI-powered diagnosis (`easybdit/laravel-mikrotik-ai`, a separate future
package) — is **not implemented yet**. See
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

### Monitoring snapshots (opt-in persistence)

P4 adds an opt-in way to record a point-in-time capture of a connection's
`resource()`, `health()`, and `interfaces()` into your application's own
database, for later inspection or trend analysis. This is entirely
additive — nothing in P1-P3 requires it, and an application that never
publishes/runs the migration below is completely unaffected.

Publish and run the migration:

```bash
php artisan vendor:publish --tag=mikrotik-migrations
php artisan migrate
```

Then record a snapshot:

```php
use Easybdit\LaravelMikrotik\Monitoring\SnapshotRecorder;

$recorder = app(SnapshotRecorder::class);

$snapshot = $recorder->record();          // default connection
$snapshot = $recorder->record('branch-01'); // a specific named connection

$snapshot->connection;   // "default"
$snapshot->captured_at;  // Carbon instance
$snapshot->resource;     // array — same shape as RouterResource::toArray()
$snapshot->health;       // array keyed by sensor name — HealthReading::toArray()
$snapshot->interfaces;   // array keyed by interface name — InterfaceCollection::toArray()
```

`record()` makes the same REST calls `resource()`/`health()`/
`interfaces()` already make (one call each) and stores their already-
normalized output as JSON columns — it does not re-interpret or
re-normalize RouterOS's response. Query recorded snapshots with the
`Easybdit\LaravelMikrotik\Models\MikrotikSnapshot` Eloquent model:

```php
use Easybdit\LaravelMikrotik\Models\MikrotikSnapshot;

$recent = MikrotikSnapshot::query()
    ->forConnection('branch-01')
    ->latest('captured_at')
    ->limit(50)
    ->get();
```

This phase does **not** include a scheduler, artisan command, or any
automatic/recurring snapshot capture — see
[Known limitations](#known-limitations). Calling `record()` is entirely
up to your application (e.g. from your own scheduled command).

### Rules and alerts (opt-in, built on snapshots)

P5 adds opt-in threshold rules, evaluated against a `MikrotikSnapshot`
(see above — this depends on P4). A rule is a plain Eloquent row you
create yourself; there is no config-file or artisan-command way to
define one in this phase.

```php
use Easybdit\LaravelMikrotik\Models\MikrotikRule;

MikrotikRule::query()->create([
    'connection' => 'branch-01', // or null to apply to every connection
    'name'       => 'High CPU load',
    'metric'     => 'resource.cpu_load',
    'operator'   => '>',           // one of: > >= < <= == !=
    'threshold'  => 90,
]);
```

`metric` is a dot path into the snapshot's stored data: the first
segment must be `resource`, `health`, or `interfaces` (matching
`MikrotikSnapshot`'s three columns), and the rest is resolved with
`Illuminate\Support\Arr::get()`, e.g. `health.cpu-temperature.value` or
`interfaces.ether1.rx_error`. An unsupported `operator` or a `metric`
that doesn't start with one of those three prefixes throws
`InvalidRuleException` immediately when the rule is saved, not later
when it happens to be evaluated.

Evaluate a snapshot against every enabled rule that applies to its
connection (a rule with `connection` left `null` applies to all of
them):

```php
use Easybdit\LaravelMikrotik\Monitoring\RuleEvaluator;

$snapshot = app(\Easybdit\LaravelMikrotik\Monitoring\SnapshotRecorder::class)->record('branch-01');

$alerts = app(RuleEvaluator::class)->evaluate($snapshot);

foreach ($alerts as $alert) {
    echo "{$alert->metric} {$alert->operator} {$alert->threshold} (was {$alert->value})\n";
}
```

A metric absent from a given snapshot (a sensor this device doesn't
expose, an interface that wasn't present) is silently skipped, the same
way `resource()`/`health()`/`interfaces()` never throw for absent device
data — it is not treated as a rule match.

Each `MikrotikAlert` is an **immutable historical record** of one rule
matching one snapshot — not a stateful "active/resolved" alert, and
repeated firings are not deduplicated or grouped into an incident in
this phase (see [Known limitations](#known-limitations); that is P10's
job). Nothing calls `RuleEvaluator::evaluate()` automatically — there is
no scheduler or artisan command yet (P6), and no framework event is
dispatched when an alert is recorded yet (P7).

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
  not add a database table or admin UI for them.
- P4's `mikrotik_snapshots` table stores only what `resource()`/
  `health()`/`interfaces()` already return to your application code
  (board info, sensor readings, interface state/counters) — never
  connection credentials. It lives in your own application's database,
  so your normal database access controls apply to it.
- P5's `mikrotik_rules` and `mikrotik_alerts` tables are the same: rule
  definitions and alert history you (or your application code) create,
  derived entirely from data `mikrotik_snapshots` already stores. Neither
  table stores or references connection credentials.
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
- Scheduled/recurring polling of any kind — `SnapshotRecorder::record()`
  (P4) captures one snapshot per call, and `RuleEvaluator::evaluate()`
  (P5) evaluates one snapshot per call; nothing in this package invokes
  either on a schedule (no artisan command, no console kernel entry)
- Any config-file or artisan-command way to define a `MikrotikRule` —
  create one directly via Eloquent (P5)
- Alert lifecycle/state — a `MikrotikAlert` (P5) is an immutable
  historical record of one rule matching one snapshot; there is no
  active/resolved status, no de-duplication of repeated firings, and no
  grouping of related alerts into an incident
- Any framework event dispatched when a snapshot is recorded or an alert
  fires — `SnapshotRecorder`/`RuleEvaluator` return the created row(s)
  directly; nothing is broadcast via Laravel's event system yet
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
