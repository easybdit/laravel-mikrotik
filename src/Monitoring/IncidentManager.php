<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Monitoring;

use Easybdit\LaravelMikrotik\Models\MikrotikAlert;
use Easybdit\LaravelMikrotik\Models\MikrotikIncident;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Groups a rule's triggered/resolved alert episode (P5) into a
 * MikrotikIncident: opens one for a newly-triggered alert, and resolves
 * the matching open one when that alert's condition clears.
 *
 * This class does not re-derive which rules matched or why — it reacts
 * purely to alert state its caller already computed: the exact
 * newly-triggered list Monitoring\RuleEvaluator::evaluate() returns, and
 * the exact "just resolved" list Console\Commands\MonitorCommand already
 * derives (the same before/after active-alert diff it already uses for
 * notifications). Nothing here duplicates RuleEvaluator's matching
 * logic.
 *
 * Deliberately does not attempt cross-rule correlation, or re-open a
 * resolved incident if the same rule/connection matches again later —
 * that is always a new, separate incident, mirroring exactly how a
 * resolved MikrotikAlert matching again creates a new historical alert
 * rather than a revived one (see RuleEvaluator's docblock).
 *
 * Safe under two concurrent `mikrotik:monitor` processes: opening an
 * incident is a single INSERT guarded by mikrotik_incidents' own unique
 * index (see its migration) — if two processes race to open the same
 * rule+connection's incident, the loser's INSERT fails with a database
 * integrity-constraint error, which is caught here and treated as "the
 * other process already opened it," not an error. This is genuine
 * database-level protection, not an application-level check with a race
 * window, and it is not a claim of distributed locking — there is none.
 */
final class IncidentManager
{
    /**
     * @param list<MikrotikAlert> $triggeredAlerts Newly-triggered alerts this run (RuleEvaluator::evaluate()'s return value).
     * @param iterable<MikrotikAlert> $justResolvedAlerts Alerts that flipped to 'resolved' this run.
     * @return array{opened: list<MikrotikIncident>, resolved: list<MikrotikIncident>}
     */
    public function reconcile(array $triggeredAlerts, iterable $justResolvedAlerts): array
    {
        $opened = [];
        $resolved = [];

        foreach ($triggeredAlerts as $alert) {
            if ($alert->rule_id === null) {
                continue; // no rule to key an incident on -- should not happen from RuleEvaluator, but never assumed.
            }

            $incident = $this->openIncidentFor($alert);

            if ($incident !== null) {
                $opened[] = $incident;
            }
        }

        foreach ($justResolvedAlerts as $alert) {
            if ($alert->rule_id === null) {
                continue;
            }

            $incident = $this->resolveIncidentFor($alert);

            if ($incident !== null) {
                $resolved[] = $incident;
            }
        }

        return ['opened' => $opened, 'resolved' => $resolved];
    }

    private function openIncidentFor(MikrotikAlert $alert): ?MikrotikIncident
    {
        try {
            $incident = MikrotikIncident::query()->create([
                'rule_id'        => $alert->rule_id,
                'first_alert_id' => $alert->id,
                'connection'     => $alert->connection,
                'metric'         => $alert->metric,
                'status'         => MikrotikIncident::STATUS_OPEN,
                'open_rule_id'   => $alert->rule_id,
                'opened_at'      => $alert->triggered_at ?? Carbon::now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isIntegrityConstraintViolation($e)) {
                // Another process already opened this rule+connection's
                // incident concurrently -- that row is authoritative; do
                // not create a second one, and do not report a fresh
                // "opened" transition for it here (the process that
                // actually inserted it did).
                return null;
            }

            throw $e;
        }

        $alert->update(['incident_id' => $incident->id]);

        return $incident;
    }

    private function resolveIncidentFor(MikrotikAlert $alert): ?MikrotikIncident
    {
        // open_rule_id (not rule_id) -- an exact match against the same
        // unique index (open_rule_id, connection) that already guards
        // against a duplicate open incident, rather than a separate
        // rule_id+status lookup needing its own index.
        $incident = MikrotikIncident::query()
            ->where('open_rule_id', $alert->rule_id)
            ->where('connection', $alert->connection)
            ->first();

        if ($incident === null) {
            // Nothing to resolve -- e.g. this alert predates P10 (no
            // incident was ever opened for it).
            return null;
        }

        $incident->update([
            'status'       => MikrotikIncident::STATUS_RESOLVED,
            'resolved_at'  => $alert->resolved_at ?? Carbon::now(),
            'open_rule_id' => null,
        ]);

        return $incident;
    }

    /**
     * SQLSTATE 23000 ("integrity constraint violation") covers a unique
     * index collision across MySQL, SQLite, and PostgreSQL alike --
     * driver-specific error text is never relied on.
     */
    private function isIntegrityConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
