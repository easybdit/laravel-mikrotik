# Changelog

All notable changes to `easybdit/laravel-mikrotik` are documented here.

## P23-P25 — Advanced query, concurrent reads, final production audit

**P23 — advanced query/filtering (`menu()`).** Added `Menu::query(array
$words = [], array $proplist = [])`, RouterOS REST's documented
`.query`/`.proplist` POST mechanism, for filtering `get()`'s simple
`?field=value` equality can't express and for limiting returned fields.
Confirmed directly against a real device across two menus
(`/interface`, `/ip/firewall/filter`): the `"#|"` stack operator
correctly ORs two conditions (`type=ether #| type=bridge` returned
11+1=12 rows, matching each condition's individual count exactly), and
`.proplist` correctly limits fields via POST — **but is confirmed
broken via a plain GET query-string parameter** (returned a list of
empty objects on a real device; not offered that way for this reason).
Comparison operators (`>`, `<`), `NOT`, and `~` (contains/regex) are
**not** confirmed and are not validated/special-cased — an unsupported
word surfaces as `RouterOsException`, like any RouterOS-side rejection.
`get()`/`find()` are completely unchanged. Uses `Transport::post()` (a
non-write "print" command, the same mechanism `interfaceRate()` already
uses), so `query()` retains the same opt-in read retry configuration
every other read already has, and never touches the write transport.

**P24 — concurrent reads (`RouterConnection::pollAll()`).** Added
`Transport::getMany()`/`RestTransport::getMany()`, fetching multiple
read-only paths concurrently via Laravel's `Http::pool()`.
`SnapshotRecorder::record()` (previously three sequential `resource()`/
`health()`/`interfaces()` calls) now uses
`RouterConnection::pollAll()`, built on `getMany()` — **measured
directly against a real device**: sequential took ~1000-1250ms total;
concurrent took ~440-565ms (bounded by `interfaces()`, the slowest of
the three) — roughly halving latency. `record()`'s own signature/
behavior/return value is completely unchanged; only how the underlying
HTTP requests are issued. `getMany()` fully supports the same opt-in
retry configuration `get()`/`post()` already have (each pooled request
retries independently under the same conditions), so enabling retry
does not silently stop applying here. A bug was found and fixed during
implementation: Laravel's `Http::pool()` returns *any* exception a
pooled request raised in place of a `Response` (not only a connection
failure) — the initial implementation only checked for
`Illuminate\Http\Client\ConnectionException` and would have thrown a
`TypeError` for any other pooled failure; fixed to mirror `send()`'s
existing behavior exactly (only a connection failure is converted,
everything else propagates unmodified). Real-device verified for
correct per-connection result association (two connection names
against the same device both returned identical, correctly-isolated
data). Cross-connection pooling (multiple *routers* polled at once)
was considered but not implemented — it would require restructuring
`MonitorCommand`'s existing, valuable per-connection error isolation
(one bad router doesn't stop the others), which was judged not worth
the added risk for this cycle.

**P25 — final production audit.** Full repository security review
(credential/secret leakage, PPP secret handling, request-body leakage,
path/ID injection, mass-assignment, write-retry safety, TLS defaults)
found no new issues beyond the P24 `getMany()` bug above, already
fixed. API consistency, DTO/raw conventions, and database
indexes/constraints (migrations, concurrency) were reviewed and found
already consistent with prior phases — no changes needed. Full test
suite: 355 tests, 754 assertions (up from 347/738), zero skipped. Final
real-device sweep: every read-only capability (`resource()`, `health()`,
`interfaces()`, `logs()`, `menu()`/`menu()->query()`, `ip()->addresses()`,
`interface()`, `firewall()->filter()`, `dhcp()->servers()`/`leases()`,
`ppp()->secrets()`, `queue()->simple()`, `ip()->pools()`/`routes()`,
`dns()`, `systemIdentity()`, `pollAll()`) confirmed working end to end
against the real device (RouterOS 7.10.2, RB3011UiAS); no write was
performed, no production configuration was touched.

## P15-P21 — Firewall filter, DHCP, PPP secrets, simple queue, IP pool, IP route, DNS + system identity

Seven typed resources added in one implementation cycle, all following
P13/P14's established `list()`/`find()`/`add()`/`update()`/`remove()`/
`enable()`/`disable()` shape wherever RouterOS actually supports each
operation — no operation is forced onto a resource that doesn't support
it (see each module below). Every field set was confirmed directly
against a real device (RouterOS 7.10.2, RB3011UiAS) before being typed,
not guessed from general RouterOS knowledge.

- **P15 — `firewall()->filter()`** (`/ip/firewall/filter`): full
  list/find/add/update/remove/enable/disable. `add()` requires `chain`.
  Real-device confirmed: RouterOS strips a `/32` suffix from a
  single-host `src-address`/`dst-address` on write (see
  `DTO\FirewallFilterRule`'s docblock).
- **P16 — `dhcp()->servers()`** (`/ip/dhcp-server`) and
  **`dhcp()->leases()`** (`/ip/dhcp-server/lease`): full CRUD +
  enable/disable on both. `add()` requires `name` (server) / `address`
  (lease).
- **P17 — `ppp()->secrets()`** (`/ppp/secret`): full CRUD +
  enable/disable. `add()` requires `name`. **Security**: RouterOS's own
  REST API returns a secret's `password` in plain text on every
  GET/PUT/PATCH response — confirmed directly against a real device.
  `DTO\PppSecret` has no `$password` property, and
  `ResponseNormalizer::normalizePppSecret()` removes the `password` key
  from `$raw` before it is ever stored on the DTO — a deliberate,
  documented exception to this package's usual "raw is the untouched
  response" contract, specifically because this one field is a live
  credential, not configuration/state. Sending a password *to* RouterOS
  via `add()`/`update()` is unaffected (that is outbound data, not
  something this package echoes back).
- **P18 — `queue()->simple()`** (`/queue/simple`): full CRUD +
  enable/disable. `add()` requires `name`.
- **P19 — `ip()->pools()`** (`/ip/pool`): `list()`/`find()`/`add()`/
  `update()`/`remove()` only — **no `enable()`/`disable()`**, confirmed
  directly against a real device that RouterOS's `/ip/pool` has no
  `disabled` property at all. `add()` requires `name`.
- **P20 — `ip()->routes()`** (`/ip/route`): full CRUD +
  enable/disable. `add()` requires `dst-address`. Flagged in its own
  docblock as this package's highest-risk write resource (a route
  change can affect reachability); real-device testing used only an
  isolated route (documentation-range `dst-address`, gateway = an idle,
  unassigned interface) and never touched the router's actual
  default/production routes (independently re-verified present and
  unchanged after the test).
- **P21 — `dns()`** (`/ip/dns`) and **`systemIdentity()`**
  (`/system/identity`): both singleton "settings" menus (no `.id`, no
  list) — `get()`/`update()` only. **A real, load-bearing finding from
  this phase's real-device testing**: a singleton menu's write does
  *not* use `PATCH <path>` with no identifier (confirmed *wrong* —
  RouterOS rejects it with `"missing or invalid resource identifier"`,
  HTTP 400, no change applied) — the correct, confirmed form is `POST
  <path>/set` (RouterOS's own `set` console command, run via REST's
  documented POST-a-command mechanism), which itself returns an **empty
  body** on success rather than the updated record. Both `update()`
  methods therefore POST the write, then issue a follow-up `get()` and
  return that. This required a new transport method,
  `Transport::postWrite()`/`RestTransport::postWrite()` — a POST that,
  like `put()`/`patch()`/`delete()`, never uses the read-path retry
  configuration. `dns()->update()` was *not* independently write-tested
  against the real device (changing DNS servers is a functionally
  significant change, not a harmless one — only `dns()->get()` was
  real-device verified); `systemIdentity()->update()` *was*
  real-device-verified end to end (temporary rename, verified, restored
  to the original value, verified).
- 132 new tests (340 total, up from 208; 729 assertions, up from 543),
  covering list/find/add/update/remove/enable-disable where applicable,
  validation, RouterOS error mapping, credential/secret sanitization,
  non-retry, and exact method/path/body per module. `composer validate`
  and `php -l` clean.
- No changes to any P1-P14 public API. No production RouterOS
  configuration was touched during real-device testing — every write
  test used a disabled-first and/or documentation-range/idle-interface
  scoped test resource, cleaned up and count-verified after each
  module; the one exception (`systemIdentity()`) modifies a
  purely-cosmetic router hostname and was restored to its original
  value, verified.

## P14 — Interface management

- Added `RouterConnection::interface(): Resources\InterfaceResource` —
  a typed, read+write API for RouterOS's `/interface` menu, following
  P13's `IpAddressResource` shape: `list()`, `find()`, `update()`,
  `enable()`, `disable()`. Returns the new `DTO\InterfaceRecord`
  (`.id`, `name`, `type`, `running`, `disabled`, `comment`). Deliberately
  named singular (`interface()`) to avoid any collision with the
  pre-existing, unrelated, read-only `interfaces()` (P1, cumulative
  traffic counters keyed by name) — that method and its
  `DTO\RouterInterface`/`InterfaceCollection` are unchanged.
- No `add()`/`remove()`: MikroTik's documentation does not confirm
  PUT/DELETE support for `/interface`, and RouterOS itself does not
  generally support creating/destroying a physical interface through
  this generic menu — not implemented, per this phase's "do not invent
  behavior" scope.
- `$id` (`find()`/`update()`/`enable()`/`disable()`) accepts either a
  RouterOS `.id` or the interface's own name (confirmed against a real
  device — RouterOS's own documented `/interface` GET example
  addresses an item by name), validated against the same
  identifier allow-list `Connection\Menu`/P13's `IpAddressResource`
  already use (own copy, same independence convention P13 established).
- **Real-device verification: complete.** Against the same live
  RouterOS **7.10.2** (RB3011UiAS) device used for P13's final
  verification, using the idle, link-down `ether7` (confirmed via
  P13's own pass to have no address assigned): `list()`/`find()` by
  name, `disable()` then `enable()` with `find()` confirming RouterOS's
  actual state after each — confirming the `disabled` field's write
  wire format is `"true"`/`"false"` for `/interface` too — then
  `update()` of the `comment` field, verified, then restored to its
  original value (`"Lan"`), with a final `find()` confirming the
  interface's state matched its pre-test baseline exactly. No
  production interface, IP address, routing, firewall, DHCP, or
  PPP/PPPoE configuration was touched, and the router was never
  rebooted. Along the way, confirmed that both P13's and P14's write
  resources require the RouterOS user's group to have the `write`
  policy — a read-only user gets `RouterOsException` with RouterOS's
  own `"not enough permissions (9)"` detail.
- No changes to any P1-P13 public API. 234 tests, 543 assertions (up
  from 208/496), composer validate clean, php -l clean.

## P13 — IP address management

- Added `RouterConnection::ip(): Resources\IpResource` and
  `IpResource::addresses(): Resources\IpAddressResource` — a typed,
  read+write API for RouterOS's `/ip/address` menu, the first resource
  built on P12's write transport: `list()`, `find()`, `add()`,
  `update()`, `remove()`, `enable()`, `disable()`. Returns/accepts the
  new `DTO\IpAddress` (`.id`, `address`, `network`, `interface`,
  `actual-interface`, `disabled`, `dynamic`, `invalid`, `comment`).
- Verified against MikroTik's own documented `/ip/address` PUT/PATCH/GET
  request-response examples (help.mikrotik.com "REST API") — DELETE's
  documented empty-body-on-success is not `/ip/address`-specific in the
  docs but is exercised by `remove()`.
- `enable()`/`disable()` are thin `update()` wrappers PATCHing
  `disabled` — RouterOS REST has no documented dedicated enable/disable
  endpoint. **Not independently confirmed against a real device**: the
  wire format assumed for the boolean `disabled` value
  (`"true"`/`"false"`, matching GET's own confirmed convention) — flagged
  in the README as pending real-device verification.
- Fixed a related transport bug found while implementing `remove()`:
  `RestTransport::send()` treated a documented empty-body success
  response (DELETE) as `MalformedResponseException` instead of success
  — it previously only ever saw non-empty bodies (get()/post()/P12's
  put()/patch() all return objects). Now returns `[]` for an empty
  successful body, for any verb.
- `add()` validates the required `address` field before sending a
  request (`InvalidResourceException`, new). `find()`/`update()`/
  `remove()`/`enable()`/`disable()` validate `$id` against the same
  allow-list `Connection\Menu` already uses (own copy — `Menu` itself is
  untouched).
- **Real-device verification: complete.** Two earlier connectivity
  attempts against the configured test device (`laravel_13_rnd`) had
  failed before authentication succeeded (a connection timeout, then an
  HTTP 401 credential rejection under a read-only user). With a
  dedicated read-write test user, a full read/write pass against a live
  RouterOS 7.10.2 (RB3011UiAS) device succeeded end to end: `list()`
  against existing `/ip/address` entries, `add()` a temporary,
  non-routable test address (`203.0.113.99/32`, RFC 5737 documentation
  range) on an idle, link-down interface (`ether7`) with no address
  previously assigned, `find()`/`list()` confirming it, `update()` of
  its `comment`, `disable()` then `enable()` with `find()` confirming
  RouterOS's actual state matched after each call — **confirming the
  `disabled` field's assumed write wire format (`"true"`/`"false"`) is
  correct** — then `remove()`, with a follow-up `list()` confirming no
  trace remained. No production address, firewall, DHCP, PPP, routing,
  or other interface was touched; no credentials were logged. The test
  device returned to its original address count (7) afterward. No
  package behavior needed to change — the real device matched every
  documented assumption this phase already made, so no code fix was
  required.
- No changes to any P1-P12 public API. 208 tests, 496 assertions (up
  from 180), composer validate clean, php -l clean.

## P12 — Write transport foundation (internal only)

- Added `put()`, `patch()`, `delete()` to `Contracts\Transport` and
  `Transport\RestTransport`, mapping to RouterOS REST's documented
  `add`/`set`/`remove` verbs (verified with `Http::fake()` —
  documentation-based; not yet exercised against a real device, since
  nothing public calls these). **Nothing in the package exposes these
  yet**: `menu()` remains GET-only exactly as in P9, and no typed write
  API exists — this is transport-layer plumbing for a future phase to
  build on.
- Write requests never use the read-path retry configuration
  (`connections.*.retry`, P9/P11), regardless of how it's set, and
  default to no retry — an ambiguous failure after a write already
  reached RouterOS cannot be safely retried without risking a duplicate
  "add" or a reapplied "set"/"remove". Verified with a regression test
  showing the *same* `RestTransport` instance, same retry config,
  retries a `get()` but not a `put()`.
- Reuses the existing `send()` error-handling path unchanged (same
  exception types, same credential sanitization) — confirmed with tests
  that a write exception never contains the configured password nor
  echoes back request-body values (relevant once a future phase writes
  sensitive router-config values, e.g. a PPP secret's password).
- No changes to any P1-P11 public API. 180 tests, 444 assertions (up
  from 166), composer validate clean, php -l clean.

## P11 — Production hardening

- Added GitHub Actions CI (`.github/workflows/tests.yml`): a matrix job
  covering every PHP/Laravel combination this package supports, each
  individually verified with `composer require --dry-run -W` before
  being added (Laravel 10/PHP 8.1-8.4, Laravel 11/PHP 8.2-8.4, Laravel
  12/PHP 8.2-8.4, Laravel 13/PHP 8.3-8.4 — 12 combinations; PHP 8.1 with
  Laravel 11+ is not listed because Composer cannot resolve it, not
  because it was untested), a separate job running the suite against a
  real MySQL 8 service container, and a `composer validate`/`php -l` job.
- **Concurrency**: creating an active alert (`RuleEvaluator`) and opening
  an incident (`IncidentManager`) are now protected by a real database
  unique index (`mikrotik_alerts.active_rule_id` /
  `mikrotik_incidents.open_rule_id`, each paired with `connection`,
  nullable while inactive so history is never restricted) instead of
  only an application-level check-then-create. Two `mikrotik:monitor`
  processes evaluating the same rule+connection at nearly the same time
  can no longer both create a duplicate active alert or open incident —
  the loser's `INSERT` fails with a genuine database integrity error,
  caught and treated as "already created by the other process."
- **Reliability**: `mikrotik:monitor`'s per-connection catch was
  broadened from `MikrotikException` to `Throwable` — a genuinely
  unexpected failure while recording/evaluating/reconciling for one
  connection (not just a MikroTik-specific one) no longer aborts
  processing of the other configured connections.
- **Security**: `timeout` and `retry.times`/`retry.sleep` are now
  clamped to safe bounds (`timeout`: 1–120s; `retry.times`: 0–10;
  `retry.sleep`: 0–30000ms) rather than passed through raw. Guzzle's own
  documented `timeout` option treats `0` as "wait indefinitely"
  (confirmed directly from `GuzzleHttp\RequestOptions`) — a
  misconfigured `MIKROTIK_TIMEOUT=0` could previously hang a request
  forever; an implausibly large `MIKROTIK_RETRY_TIMES` could previously
  retry hundreds of thousands of times.
- **Performance**: `MonitoringAnalytics::alertHistory()`,
  `openIncidents()`, and `incidentHistory()` now eager-load the `rule`
  relation, closing an N+1 query pattern for the expected "list +
  read `->rule->name`" usage of these methods. Migration review found
  `mikrotik_alerts.snapshot_id`/`incident_id` and
  `mikrotik_incidents.rule_id` had no covering index outside of MySQL's
  automatic FK indexing (`->constrained()` does not create a portable
  index on SQLite/PostgreSQL) — added explicit indexes for all three, on
  the still-unreleased P5/P10 migrations. `IncidentManager`'s
  "find the open incident to resolve" lookup now queries by
  `open_rule_id` directly (an exact match against the same unique index
  above) instead of a separate `rule_id`+`status` lookup.
- No changes to any P1-P10 public API or observable single-process
  behavior; `interfaces()`/`interfaceRate()`/`menu()` remain exactly one
  RouterOS request each (verified by re-reading each, not just assumed).
  166 tests, 426 assertions (up from 159), composer validate clean,
  php -l clean on every changed file.

## P10 — Incident grouping over the alert lifecycle

- Added `Models\MikrotikIncident` and `Monitoring\IncidentManager`:
  groups a rule's P5 triggered/resolved alert episode into a longer-lived
  record. `IncidentManager::reconcile($triggeredAlerts, $justResolvedAlerts)`
  opens an incident for a newly-triggered alert and resolves the matching
  open one when that alert's condition clears — it does not re-derive
  rule-matching logic, only reacts to alert state its caller (currently
  `mikrotik:monitor`) already computed.
- In this phase, one incident maps one-to-one onto one alert episode: a
  missing metric does not resolve an open incident, and a rule matching
  again after resolution always opens a new, separate incident rather
  than reopening the old one — mirroring P5's own alert dedup/resolution
  behavior exactly. No cross-rule correlation or multi-episode grouping
  (e.g. flapping) is attempted.
- Added `mikrotik_incidents` (new migration) and `mikrotik_alerts.incident_id`
  (added to the existing, still-unreleased P5 migration rather than a
  separate one) linking an alert to its incident. Opening an incident is
  protected by a real database unique index
  (`(open_rule_id, connection)`, nullable while not open) rather than an
  application-level check — a concurrent duplicate-open attempt fails as
  a database integrity error, which `IncidentManager` catches and treats
  as "already opened by another process," not an error.
- `mikrotik:monitor` (P6) now also reconciles incidents every run and
  reports opened/resolved counts in its console output. Notification
  timing (P7) is unchanged — incident transitions currently coincide
  exactly with the alert transitions it already notified on.
- Extended `Monitoring\MonitoringAnalytics` (P8) with `openIncidents()`,
  `incidentHistory()`, `incidentCountsByRule()`, and
  `averageIncidentDurationSeconds()` (computed in PHP from
  `opened_at`/`resolved_at`, the same portable approach
  `interfaceRateHistory()` already used, not a database-specific
  date-diff function).
- Fixed a pre-existing P5 bug found while building this phase's own
  required "global rule" test: `RuleEvaluator`'s dedup lookup filtered
  only by `rule_id`, not `connection` — a rule with `connection: null`
  (applies to every connection) already active for one connection was
  incorrectly treated as "already active" for a second, unrelated
  connection too, silently suppressing that connection's own alert. Now
  scoped by `(rule_id, connection)`, matching how `MikrotikAlert` itself
  already stores connection per episode. No existing single-connection
  test's behavior changes; a dedicated regression test was added.
- No changes to any P1-P9 public API; entirely additive aside from the
  RuleEvaluator fix above.

## P9 — Generic read-only menu access + opt-in retry

- Added `RouterConnection::menu(string $path): Connection\Menu`: generic,
  read-only access to any RouterOS REST menu this package does not have
  a named/typed method for, e.g. `$router->menu('ip/address')->get()`.
  Reuses `Transport::get()` exactly as every named method already does —
  no new HTTP verb, no write operation (`add()`/`set()`/`remove()`/
  `enable()`/`disable()` are explicitly out of scope for this phase).
  `get()` returns an `Illuminate\Support\Collection` of raw, untyped
  associative arrays (no schema/type guessing for an arbitrary menu);
  `find(string $id)` fetches a single item by `.id` or name.
- Added `Exceptions\InvalidMenuPathException`: every menu path and item
  identifier passed to `menu()`/`find()` is validated against a strict
  allow-list before being used, rejecting path traversal (`..`),
  absolute-URL/protocol-like input, and any character outside what a
  RouterOS menu path or identifier can legitimately contain.
- `find()` deliberately does not convert a "not found" condition to
  `null` — RouterOS's documented error shape for a `GET`-by-id targeting
  an unknown identifier was not independently confirmed (only the
  equivalent for `DELETE` is, per official documentation), so any
  failure surfaces as the existing `RouterOsException`/
  `AuthenticationException`/`ConnectionException` hierarchy unchanged.
- Added opt-in per-connection HTTP retry (`config('mikrotik.connections.
  <name>.retry')`, keys `times`/`sleep`), using Laravel's own `Http`
  client retry mechanism. Defaults to `times: 0` — identical to every
  version of this package before P9. Retries only a transient connection
  failure or a RouterOS-side `5xx`; never a `401`/`403` or any other
  `4xx`.
- No changes to any P1-P8 public API, database schema, or default
  behavior; this phase is entirely additive.

## P8 — Monitoring analytics

- Added `Easybdit\LaravelMikrotik\Monitoring\MonitoringAnalytics`:
  read-only history/trend queries over data P4/P5 already store —
  `resourceTrend()`, `healthTrend()`, `interfaceCounterTrend()` (covers
  error/drop tracking), `interfaceRateHistory()` (a per-second rate
  computed between consecutive stored snapshots, flagging a counter
  reset instead of returning a negative number), `alertHistory()`, and
  `alertCountsByRuleMetric()`.
- Makes no RouterOS requests and no schema changes — every query filters
  on the `connection` + `captured_at`/`triggered_at` columns the P4/P5
  migrations already index before touching a row's JSON columns.

## P7 — Notifications

- Added `Easybdit\LaravelMikrotik\Monitoring\AlertNotifier`: optional
  mail/webhook delivery for a `MikrotikAlert`, gated by
  `config('mikrotik.notifications.*')` (both disabled by default — a
  silent no-op with neither configured).
- Added `Notifications\MikrotikAlertNotification` (mail + a generic JSON
  webhook payload) and `Notifications\Channels\WebhookChannel`. Delivery
  uses Laravel's on-demand/anonymous notifiable
  (`Notification::route(...)`) — no host `User`/Notifiable model
  required. Synchronous by design, no queue infrastructure added.
- `mikrotik:monitor` (P6) now calls `AlertNotifier` for every alert that
  newly became `triggered` or `resolved` during that run.

## P6 — Monitoring

- Added the `mikrotik:monitor` artisan command
  (`Console\Commands\MonitorCommand`): records a snapshot
  (`SnapshotRecorder`, P4) and evaluates it (`RuleEvaluator`, P5) for one
  or more configured connections. One connection failing does not stop
  the others. This package registers no schedule entry of its own — see
  the README for wiring it to Laravel's `Schedule` with
  `->withoutOverlapping()`.

## P5 — Rules + alerts

- Added `Easybdit\LaravelMikrotik\Models\MikrotikRule`: a threshold rule
  (`connection`, `metric`, `operator`, `threshold`, `enabled`) scoped to
  one connection or, with `connection` left null, every connection.
  Validates `operator` (`>`, `>=`, `<`, `<=`, `==`, `!=`) and `metric`'s
  `resource.`/`health.`/`interfaces.` prefix on save, throwing the new
  `InvalidRuleException` immediately for either problem.
- Added `Easybdit\LaravelMikrotik\Models\MikrotikAlert`: a record of one
  rule matching one snapshot, carrying a `status` (`triggered`/
  `resolved`) and `resolved_at`. History is never deleted — a rule
  transitions its own alert between states rather than being replaced.
  No incident grouping of a rule's triggered/resolved cycles in this
  phase.
- Added `Easybdit\LaravelMikrotik\Monitoring\RuleEvaluator::evaluate()`:
  evaluates a `MikrotikSnapshot` (P4) against every enabled rule that
  applies to its connection and maintains each rule's alert lifecycle —
  creates a `triggered` alert on a new match, deduplicates (no new row)
  while that alert is still active, and resolves it once the rule no
  longer matches. A metric absent from the snapshot is skipped, not an
  error, and does not resolve an existing active alert.
- Added publishable migrations (same `mikrotik-migrations` tag) creating
  the `mikrotik_rules` and `mikrotik_alerts` tables.
- No changes to any P1-P4 public API; this phase is entirely additive
  and inert for applications that never publish/run the new migrations
  or create a `MikrotikRule`. `RuleEvaluator` is not wired into
  `SnapshotRecorder` or any scheduler — calling it is up to the
  application (P6 will add a reliable/scheduled command).

## P4 — Monitoring snapshots

- Added opt-in database persistence of point-in-time monitoring
  snapshots: `Easybdit\LaravelMikrotik\Monitoring\SnapshotRecorder`
  captures a connection's `resource()`/`health()`/`interfaces()` output
  and stores it via the new `Easybdit\LaravelMikrotik\Models\MikrotikSnapshot`
  Eloquent model.
- Added a publishable migration (`mikrotik-migrations` tag) creating the
  `mikrotik_snapshots` table.
- Added `illuminate/database` to `require` (needed for the Eloquent model
  and migration).
- No changes to any P1-P3 public API; this phase is entirely additive
  and inert for applications that never publish/run the new migration.

## P1 + P2 + P3

- P1: MikroTik RouterOS REST connectivity, and read-only `resource()`/
  `health()` retrieval.
- P2: `interfaces()` (with cumulative traffic counters) and `logs()`.
- P3: `interfaceRate()` — one-shot instantaneous interface rate reading.

See the README for full usage and verification notes for each phase.
