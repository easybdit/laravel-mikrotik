# Changelog

All notable changes to `easybdit/laravel-mikrotik` are documented here.

## P5 — Rules + alerts

- Added `Easybdit\LaravelMikrotik\Models\MikrotikRule`: a threshold rule
  (`connection`, `metric`, `operator`, `threshold`, `enabled`) scoped to
  one connection or, with `connection` left null, every connection.
  Validates `operator` (`>`, `>=`, `<`, `<=`, `==`, `!=`) and `metric`'s
  `resource.`/`health.`/`interfaces.` prefix on save, throwing the new
  `InvalidRuleException` immediately for either problem.
- Added `Easybdit\LaravelMikrotik\Models\MikrotikAlert`: an immutable
  record of one rule matching one snapshot (not a stateful active/
  resolved alert — no de-duplication or incident grouping in this
  phase).
- Added `Easybdit\LaravelMikrotik\Monitoring\RuleEvaluator::evaluate()`:
  evaluates a `MikrotikSnapshot` (P4) against every enabled rule that
  applies to its connection and records a `MikrotikAlert` for each match.
  A metric absent from the snapshot is skipped, not an error.
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
