# Changelog

All notable changes to `easybdit/laravel-mikrotik` are documented here.

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
- **Real-device verification: still pending, not skipped.** Two
  separate read-only connectivity attempts against the configured test
  device (`laravel_13_rnd`), on two different occasions: the first
  returned a connection timeout; a follow-up attempt reached the device
  successfully but had its credentials rejected (HTTP 401) — two
  different failure modes, both recorded as observed rather than
  assumed. No write was attempted against the device either time (both
  stopped at connectivity/auth, before any `/ip/address` request could
  be made). Verified with `Http::fake()` against official documentation
  only, per this phase's own stop-and-report rule for an
  unreachable/unauthenticated device.
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
