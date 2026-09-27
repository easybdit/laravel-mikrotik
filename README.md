# easybdit/laravel-mikrotik

A Laravel abstraction for MikroTik RouterOS devices.

**This is P1 — an intentionally small first release.** It provides
connection management and two read-only endpoints (router resource info
and hardware health) over the RouterOS REST API. Everything else —
interfaces, traffic, logs, DHCP/PPP/firewall/VPN, monitoring, alerting,
a binary-API transport, and AI-powered diagnosis (`easybdit/laravel-mikrotik-ai`,
a separate future package) — is **not implemented yet**. See
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
- The package never logs a request it makes, and in particular never
  logs the `Authorization` header.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The test suite uses `Illuminate\Support\Facades\Http::fake()` throughout
— no physical MikroTik router is required to run it.

## Known limitations

P1 intentionally does **not** include:

- Interfaces, traffic counters, or logs
- DHCP, PPP/PPPoE, firewall, or VPN modules
- Monitoring persistence, scheduled polling, or alerting
- A binary RouterOS API transport (port 8728/8729) — REST only
- Any AI integration (`easybdit/laravel-mikrotik-ai` is a separate,
  not-yet-built package; this package has no dependency on
  `easybdit/laraveleasyai` and never will)
- Multi-tenant / SaaS functionality
- Database-backed router credentials — configuration is `.env`/config
  file based only

## License

MIT
