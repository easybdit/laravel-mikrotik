# Changelog

All notable changes to `easybdit/laravel-mikrotik` are documented here.

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
