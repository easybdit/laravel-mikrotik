# Changelog

All notable changes to `easybdit/laravel-mikrotik` are documented here.

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
