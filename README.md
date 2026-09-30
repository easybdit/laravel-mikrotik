# easybdit/laravel-mikrotik

A Laravel abstraction for MikroTik RouterOS devices.

**This is P1 through P12 — an intentionally small, incrementally-grown
release.** It provides connection management and read-only retrieval of
router resource info, hardware health, interfaces (with cumulative
traffic counters and a one-shot rate reading), log entries, opt-in
database persistence of point-in-time monitoring snapshots, opt-in
threshold rules with a triggered/resolved alert lifecycle, incident
grouping above that alert lifecycle, a Laravel-scheduler-friendly
`mikrotik:monitor` command, optional mail/webhook alert notifications,
read-only history/trend analytics over already-recorded data (including
incidents), generic read-only RouterOS REST menu access for any menu
this package doesn't have a named method for, and opt-in HTTP retry —
all over the RouterOS REST API. P12 additionally adds an internal write
transport (`PUT`/`PATCH`/`DELETE`) as a foundation for future typed
write APIs — it is **not** exposed through `menu()` or any public method
yet (see [Write transport foundation](#write-transport-foundation-p12-internal-only)).
Everything else — DHCP/PPP/firewall/VPN, any actual write/configuration
operation, a binary-API transport, router CRUD/management, cross-rule
incident correlation, and AI-powered diagnosis
(`easybdit/laravel-mikrotik-ai`, a separate future package) — is **not
implemented yet**. See [Known limitations](#known-limitations) below for
the authoritative list.

## Requirements

- A MikroTik router running **RouterOS v7.1beta4 or later**, with the
  `www-ssl` service enabled (Winbox/WebFig → IP → Services → `www-ssl`)
- One of the PHP/Laravel combinations below — each is individually
  verified installable with Composer (not just declared in
  `composer.json`) and covered by CI (`.github/workflows/tests.yml`):

  | Laravel | Testbench | PHP |
  |---|---|---|
  | 10 | ^8.0 | 8.1, 8.2, 8.3, 8.4 |
  | 11 | ^9.0 | 8.2, 8.3, 8.4 |
  | 12 | ^10.0 | 8.2, 8.3, 8.4 |
  | 13 | ^11.0 | 8.3, 8.4 |

  A combination not listed here (e.g. PHP 8.1 with Laravel 11+) is not
  supported — Composer cannot resolve it, since Laravel itself raised its
  own minimum PHP version partway through that range. The test suite
  runs on both SQLite and MySQL in CI.

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

### Generic menu access (P9)

`resource()`, `health()`, `interfaces()`, `interfaceRate()`, and `logs()`
are named, typed methods for five specific RouterOS menus. For anything
else, `menu()` gives generic read-only access to any RouterOS REST menu,
without waiting for this package to add a named method for it:

```php
$addresses = $router->menu('ip/address')->get();

foreach ($addresses as $address) {
    echo "{$address['address']} on {$address['interface']}\n";
}

// Simple equality filtering -- the same `?field=value` form logs() uses:
$onBridge1 = $router->menu('ip/address')->get(['interface' => 'bridge1']);

// A single item by its RouterOS ".id" (e.g. "*1") or, where the menu
// supports it, its name (e.g. "ether1"):
$item = $router->menu('interface')->find('ether1');
```

`get()` returns an `Illuminate\Support\Collection` of **raw, untyped
associative arrays** — exactly RouterOS's REST JSON as-is (every value
still a JSON-encoded string, per RouterOS REST's own convention). Unlike
the five named methods above, `menu()` has no fixed schema to safely
guess field types from for an arbitrary menu, so it never attempts to —
normalize whatever fields you need yourself.

`find()` does **not** convert a "not found" condition to `null`.
RouterOS's documented error shape for a `DELETE` on an unknown identifier
is confirmed (`{"error":404,"message":"Not Found"}`), but the equivalent
for a `GET` was not independently confirmed against a live device, so
this deliberately does not guess which failure to swallow — any error,
including "not found", surfaces as the same `RouterOsException` (or
`AuthenticationException`/`ConnectionException`, as appropriate) every
other RouterOS-side failure already does.

`menu()` only ever issues a `GET` (RouterOS's documented `print`) —
**there is no `add()`/`set()`/`remove()`/`enable()`/`disable()` in this
phase**, and every menu path and item identifier is validated against a
strict allow-list before use, so a caller cannot escape the `/rest/` API
root, inject a scheme/host, or attempt a path-traversal segment (`..`) —
an invalid one throws `InvalidMenuPathException` immediately rather than
being sent to the router at all.

*A brief note on positioning: generic read-only RouterOS REST menu
access is provided without requiring callers to use a menu-specific DTO
— useful once you need a menu this package hasn't named yet, while the
five typed methods above remain the richer, safer choice for what they
already cover.*

### Write transport foundation (P12, internal only)

P12 adds `put()`/`patch()`/`delete()` to this package's internal
`Transport` contract — RouterOS REST's documented `add`/`set`/`remove`
verbs. **Nothing public calls these yet**: `menu()` remains exactly as
described above (GET-only), and no typed write API exists before a
future phase. This is transport-layer plumbing only, verified with
`Http::fake()` (exact method/path/body, error mapping, credential and
request-body sanitization) — not yet exercised against a real device,
since nothing exposes it.

Write requests **never** use the read-path retry configuration
(`connections.*.retry`, see [Retry](#retry-opt-in-p9) below), regardless
of how it's set — an ambiguous failure (timeout, dropped connection)
after a write already reached RouterOS cannot be safely retried without
risking a duplicate "add" or a reapplied "set"/"remove". Write retry is
not implemented at all in this phase.

### Retry (opt-in, P9)

Every connection can opt into retrying a request that failed for a
transient reason:

```php
'connections' => [
    'default' => [
        // ...
        'retry' => [
            'times' => 3,   // 0 (default) = no retry, identical to every version of this package before P9
            'sleep' => 500, // milliseconds between attempts
        ],
    ],
],
```

Only a transient connection failure (timeout/DNS/refused) or a
RouterOS-side `5xx` response is retried — **never** a `401`/`403`
(`AuthenticationException`) or any other `4xx` (a RouterOS-side
rejection, e.g. a bad query, which retrying would not change). This is
safe to enable for every method in this package: P9 added no write
operation, and the one existing `POST` use
(`interfaceRate()`'s `monitor-traffic ... once`) is idempotent. Retry is
per connection, uses Laravel's own `Http` client retry mechanism (no
second retry/locking system was built for this), and does not change
`timeout` behavior.

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
data — it is not treated as a rule match, and does **not** resolve an
existing active alert (see lifecycle below).

Each `MikrotikAlert` carries a simple two-state lifecycle, tracked in
`status` (`'triggered'` or `'resolved'`) and `resolved_at`:

- A rule matches and has no active alert yet → a new alert is created
  with `status = 'triggered'`.
- A rule matches again while its alert is still active → **no duplicate
  row** is created (deduplication).
- A rule stops matching while it has an active alert → that alert is
  flipped to `status = 'resolved'`, `resolved_at` set — never deleted.
- The same rule matches again afterwards → since no active alert
  remains, a **new, separate** historical alert row is created.

`RuleEvaluator::evaluate()` only returns newly-created (`'triggered'`)
alerts; a resolution or a deduplicated repeat produces no entry in the
returned array, though the underlying row is still updated/left as-is.
History is never deleted — query `MikrotikAlert::query()->resolved()` /
`->active()` for either state.

### Incidents (P10, built on the alert lifecycle)

An alert (above) is one row per continuous triggered→resolved episode
of one rule. An **incident** wraps that same episode in a longer-lived,
stable record — `Monitoring\IncidentManager::reconcile()` opens one the
moment `RuleEvaluator` creates a new `'triggered'` alert, and resolves
it the moment that alert flips to `'resolved'`:

```php
use Easybdit\LaravelMikrotik\Monitoring\IncidentManager;

$transitions = app(IncidentManager::class)->reconcile($triggeredAlerts, $justResolvedAlerts);

$transitions['opened'];   // list<MikrotikIncident> newly opened this call
$transitions['resolved']; // list<MikrotikIncident> newly resolved this call
```

`mikrotik:monitor` (P6) already calls this for you every run — see
below. `$triggeredAlerts`/`$justResolvedAlerts` are exactly what
`RuleEvaluator::evaluate()` returns and what the command already derives
for its own notifications; `IncidentManager` does not re-derive rule
matching itself.

**In this phase, one incident maps one-to-one onto one alert episode** —
P5's own alert deduplication already guarantees at most one active alert
per rule (and, per connection, for a global rule — see below), so there
is nothing to group across *multiple* alert rows yet:

- Repeated matching snapshots reuse the same open incident (no
  duplicate rows) — mirrors the alert's own dedup exactly.
- A missing metric leaves an open incident untouched, the same way it
  leaves the alert untouched — it is never treated as "resolved."
- The same rule matching again after resolution opens a **new, separate**
  incident, mirroring how a resolved alert matching again creates a new
  historical alert rather than reviving the old one.
- `MikrotikAlert::$incident_id` links an alert to its incident (nullable
  — an alert recorded before P10, or one somehow evaluated without a
  rule, has none). `MikrotikIncident::firstAlert()`/`alerts()` are the
  reverse relations.

Safe under two concurrent `mikrotik:monitor` processes: opening an
incident is a single `INSERT` guarded by a real database unique index
(`mikrotik_incidents`' `(open_rule_id, connection)`, nullable-while-open
so any number of *resolved* incidents for the same rule/connection
coexist freely) — if two processes race to open the same rule's
incident, the loser's `INSERT` fails with a genuine database
integrity-constraint error, which `IncidentManager` catches and treats
as "the other process already opened it," not an error. This is real
database-level protection, not a claim of distributed locking (there is
none).

Notification timing is **unchanged** by P10: `mikrotik:monitor` still
notifies from the same alert triggered/resolved transitions it already
did in P7 (see below) — since incident transitions currently coincide
exactly with those, this is not a behavior change today, only correct
groundwork for a future phase that might group multiple alert episodes
into one incident (not implemented — see
[Known limitations](#known-limitations)).

### Concurrency (P11)

Two `mikrotik:monitor` processes (e.g. an overlapping scheduled run, or
one run manually while another is still in flight) evaluating the same
rule+connection at nearly the same time cannot both create an active
alert or an open incident for it. Both `mikrotik_alerts` and
`mikrotik_incidents` carry a real database **unique index**
(`(active_rule_id, connection)` / `(open_rule_id, connection)`,
nullable-while-inactive so history is never restricted) — the loser's
`INSERT` fails with a genuine database integrity-constraint error, which
`RuleEvaluator`/`IncidentManager` catch and treat as "the other process
already created it," not an error, and not a duplicate row. This is a
real database-level guarantee, not an application-level check with a
race window, and it is **not** a claim of distributed locking — none is
implemented. Prefer `->withoutOverlapping()` on Laravel's scheduler (see
[Scheduled monitoring](#scheduled-monitoring-mikrotikmonitor-p6)) to
avoid overlapping runs in the first place; this is the safety net for
when that still happens (a manual run, a missed lock, etc.).

### Scheduled monitoring (`mikrotik:monitor`, P6)

P6 adds an artisan command that ties P4 and P5 together: for one or more
connections, it records a snapshot (`SnapshotRecorder`) and evaluates it
(`RuleEvaluator`) in one step. Since P10, it also reconciles incidents
(`IncidentManager`) from the same result and reports opened/resolved
counts in its own console output — see
[Incidents](#incidents-p10-built-on-the-alert-lifecycle) above.

```bash
php artisan mikrotik:monitor                          # every configured connection
php artisan mikrotik:monitor --connection=branch-01    # a specific one (repeatable)
```

This package does **not** register its own schedule entry — put it on
Laravel's own scheduler, in your application's `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('mikrotik:monitor')->everyFiveMinutes()->withoutOverlapping();
```

`->withoutOverlapping()` is Laravel's own cache-based lock; this package
deliberately does not build a second one. Each connection is handled
independently inside the command: a failure capturing one (unreachable
router, rejected credentials, a RouterOS-side error, or — P11 — any
other unexpected failure, e.g. a database error) is reported via a
console error line and does not stop the others, and each run is
independent of the last (no state carried between invocations) — which
is what makes running it repeatedly/on a schedule safe. The command
exits successfully if at least one connection succeeded.

### Notifications (opt-in, P7)

P7 adds optional mail/webhook delivery when `mikrotik:monitor` (P6)
observes an alert newly become `'triggered'` or `'resolved'`. Both
channels are disabled by default — set the relevant `MIKROTIK_NOTIFY_*`
variables to enable one or both:

```env
MIKROTIK_NOTIFY_MAIL_ENABLED=true
MIKROTIK_NOTIFY_MAIL_TO=ops@example.com

MIKROTIK_NOTIFY_WEBHOOK_ENABLED=true
MIKROTIK_NOTIFY_WEBHOOK_URL=https://example.com/hooks/mikrotik
```

With neither variable set, `Monitoring\AlertNotifier::notify()` is a
silent no-op — Laravel's `Notification` facade is never touched. You can
also call it directly for your own alerts:

```php
use Easybdit\LaravelMikrotik\Monitoring\AlertNotifier;

app(AlertNotifier::class)->notify($alert); // $alert: MikrotikAlert
```

Delivery uses Laravel's own on-demand/anonymous notifiable
(`Notification::route(...)`) rather than a host application's `User`
model — this package has no concept of a "user" to notify. The webhook
channel POSTs a JSON payload (`id`, `status`, `connection`, `metric`,
`operator`, `threshold`, `value`, `message`, `triggered_at`,
`resolved_at`) to the configured URL. Delivery is synchronous (no
queueing) — queue it yourself if you need that.

### Monitoring analytics (P8, extended in P10)

P8 adds read-only history/trend queries over data P4 and P5 already
store — it makes **no RouterOS requests of its own** and does not change
`mikrotik_snapshots`/`mikrotik_alerts`/`mikrotik_incidents` in any way.

```php
use Easybdit\LaravelMikrotik\Monitoring\MonitoringAnalytics;

$analytics = app(MonitoringAnalytics::class);

$analytics->resourceTrend('branch-01', 'cpu_load', since: now()->subDay());
$analytics->healthTrend('branch-01', 'cpu-temperature');
$analytics->interfaceCounterTrend('branch-01', 'ether1', 'rx_error'); // also covers tx_error/rx_drop/tx_drop
$analytics->interfaceRateHistory('branch-01', 'ether1', 'rx_byte');
$analytics->alertHistory('branch-01');
$analytics->alertCountsByRuleMetric('branch-01');

// P10:
$analytics->openIncidents('branch-01');              // what's ongoing right now
$analytics->incidentHistory('branch-01');             // any status, oldest first
$analytics->incidentCountsByRule('branch-01');        // "which problem recurs most"
$analytics->averageIncidentDurationSeconds('branch-01'); // resolved incidents only; null if none
```

`interfaceRateHistory()` computes a per-second rate between each pair of
consecutive stored snapshots from their already-recorded cumulative
counters — contrast `RouterConnection::interfaceRate()` (P3), which is
RouterOS's own live one-shot reading at the moment of the call. A pair
where the counter decreased (RouterOS resets these on interface reset —
typically a reboot) is reported as `rate_per_second: null, counter_reset:
true` rather than a misleading negative number. Every query here filters
first on the `connection` + `captured_at`/`triggered_at`/`opened_at`
columns already indexed by the P4/P5/P10 migrations before touching a
row's JSON columns. `averageIncidentDurationSeconds()` computes the
average in PHP from `opened_at`/`resolved_at` (the same portable
approach `interfaceRateHistory()` already uses), not a
database-specific date-diff function.

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
- P7's `config('mikrotik.notifications')` (mail/webhook) holds only a
  destination address/URL, never router credentials — the webhook
  payload (see [Notifications](#notifications-opt-in-p7)) carries the
  same alert fields `mikrotik_alerts` already stores and nothing else.
  Both channels are disabled by default.
- P8's `MonitoringAnalytics` is read-only: it makes no RouterOS request
  and does not write to any table.
- P9's `menu()` validates every menu path and item identifier against a
  strict allow-list (letters/digits/`_`/`-`/`/` for paths; adds `.`/`*`
  for identifiers) before ever building a request — a path traversal
  (`..`), an absolute URL, or protocol-like input (`http://...`) is
  rejected with `InvalidMenuPathException` and never reaches the
  transport layer at all.
- `menu()` results are raw RouterOS REST data (the same fields
  `resource()`/`health()`/`interfaces()` etc. already receive) — never
  connection credentials, and never logged.
- P9's retry never retries a `401`/`403`, so enabling it cannot turn a
  single rejected-credentials attempt into repeated ones, and it adds no
  new place where the `Authorization` header could be logged/echoed —
  the same request-building code path (and the same never-log-a-request
  rule) is reused for every attempt.
- P10's `mikrotik_incidents` table is the same: it stores only
  `rule_id`/`connection`/`metric`/status/timestamps derived from
  `mikrotik_alerts`, which already stores no credentials. Opening an
  incident is protected by a real database unique index (not an
  application-level check with a race window), so a duplicate-open
  attempt fails as a database integrity error, caught and handled by
  `IncidentManager` — never silently retried, never logged.
- P11: `timeout` and `retry.times`/`retry.sleep` are clamped to safe
  bounds (`timeout`: 1–120s; `retry.times`: 0–10; `retry.sleep`:
  0–30000ms) rather than passed through raw. Guzzle's own documented
  `timeout` option treats `0` as "wait indefinitely" — a misconfigured
  `MIKROTIK_TIMEOUT=0` (or an implausibly large value from a bad env
  var) can no longer hang a request forever or retry hundreds of
  thousands of times. `verify_tls` still defaults to `true` and is never
  disabled automatically by any code path.
- P11: creating an active alert (`RuleEvaluator`) or opening an incident
  (`IncidentManager`) is now protected by a real database unique index,
  not just an application-level check-then-create — closing a genuine
  race between two `mikrotik:monitor` processes evaluating the same
  rule+connection at nearly the same time. See
  [Concurrency](#concurrency-p11) below.
- P12: the new `put()`/`patch()`/`delete()` transport methods reuse the
  exact same credential-handling and exception-sanitization code path as
  `get()`/`post()` — no new place exists where a password or the
  `Authorization` header could leak. Write exceptions reflect only
  RouterOS's *response*, never the request body sent — tested explicitly
  since a future phase's request bodies may carry sensitive router-config
  values (e.g. a PPP secret's password). Writes never use the read-path
  retry configuration (see
  [Write transport foundation](#write-transport-foundation-p12-internal-only)
  above).

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

CI (`.github/workflows/tests.yml`, P11) runs this suite against every
combination in [Requirements](#requirements) above on SQLite, plus one
additional run (PHP 8.4, Laravel 13) against a real MySQL 8 service
container — this package's migrations are written to be portable across
both (see `database/migrations/`), not SQLite-only.

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
- A built-in schedule entry or locking — `mikrotik:monitor` (P6) is a
  plain artisan command; you register it (and `->withoutOverlapping()`)
  on Laravel's own scheduler yourself (see
  [Scheduled monitoring](#scheduled-monitoring-mikrotikmonitor-p6))
- Any config-file or artisan-command way to define a `MikrotikRule` —
  create one directly via Eloquent (P5)
- Cross-rule incident correlation — an incident (P10) groups exactly one
  rule's own triggered/resolved episode; it does not attempt to relate
  incidents from *different* rules that might share a root cause
- Grouping *multiple* alert episodes of the same rule into one incident
  (e.g. brief flapping in and out of the threshold) — P10 maps one
  incident to exactly one alert episode; a rule that resolves and
  re-matches shortly after always opens a new, separate incident, never
  reopens the previous one (see
  [Incidents](#incidents-p10-built-on-the-alert-lifecycle))
- Any framework event (Laravel event/listener) dispatched when a
  snapshot is recorded, an alert fires, or an incident opens/resolves —
  P7 added mail/webhook *notifications* specifically (see
  [Notifications](#notifications-opt-in-p7)), but there is no general
  `Illuminate\Events` hook yet
- Notification channels beyond mail/webhook (no Slack, SMS, etc.), and
  no queueing of notification delivery (P7 is synchronous by design)
- Any way to change RouterOS's own configuration — `menu()` (P9) is
  GET-only, no public API calls the P12 write transport, and there is no
  `add()`/`set()`/`remove()`/`enable()`/`disable()` on anything yet (see
  [Write transport foundation](#write-transport-foundation-p12-internal-only))
- Retry of any kind for write operations — P12's `put()`/`patch()`/
  `delete()` never retry, and there is no separate "write retry" setting
  to opt into
- Filtering beyond simple equality on `menu()->get()` — no RouterOS
  `.query`/`~`/operator-based filtering or `POST`-based filtering, only
  the same `?field=value` form `logs()` already uses (see
  [Generic menu access](#generic-menu-access-p9)); not implemented until
  independently verified
- A confirmed not-found behavior for `menu()->find()` — it currently
  surfaces RouterOS's generic error response (`RouterOsException`)
  rather than returning `null`, because the exact `GET`-by-id "not
  found" error shape was not independently confirmed against a live
  device (see [Generic menu access](#generic-menu-access-p9))
- Concurrent/batched RouterOS requests (e.g. the binary API's
  tag-correlated multiplexing over one socket) — REST is one request per
  response; nothing in this package sends multiple requests at once
- Distributed locking (P11's concurrency protection is a real database
  unique index, not a lock — see [Concurrency](#concurrency-p11); two
  processes racing for the same rule/incident never both succeed, but
  nothing coordinates processes beyond that)
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

`menu()` (P9) is a generic accessor: whatever RouterOS "read" policy
your user needs for a given menu is entirely between your RouterOS user
configuration and that menu — this package cannot verify permissions
per menu on your behalf.

## License

MIT
