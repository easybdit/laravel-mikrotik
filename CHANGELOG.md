# Changelog

All notable changes to `easybdit/laravel-mikrotik` are documented here.

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
