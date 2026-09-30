<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Console\Commands;

use Easybdit\LaravelMikrotik\Exceptions\MikrotikException;
use Easybdit\LaravelMikrotik\Models\MikrotikAlert;
use Easybdit\LaravelMikrotik\Monitoring\AlertNotifier;
use Easybdit\LaravelMikrotik\Monitoring\IncidentManager;
use Easybdit\LaravelMikrotik\Monitoring\RuleEvaluator;
use Easybdit\LaravelMikrotik\Monitoring\SnapshotRecorder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Records one MikrotikSnapshot (P4) and evaluates it against P5's rules,
 * for one or more configured connections, in a single artisan command a
 * host application can put on Laravel's own scheduler:
 *
 *   use Illuminate\Support\Facades\Schedule;
 *   Schedule::command('mikrotik:monitor')->everyFiveMinutes()->withoutOverlapping();
 *
 * This package deliberately does not register its own schedule entry or
 * locking -- Laravel's scheduler (and ->withoutOverlapping()'s
 * cache-based lock) already solves "run this periodically" and "don't
 * let two runs overlap"; building a second version of either here would
 * just be a duplicate, harder-to-configure scheduler architecture.
 *
 * Each connection is handled independently: a failure capturing one
 * (unreachable router, rejected credentials, a RouterOS-side error) is
 * reported via a component error line and does not stop the others from
 * being processed. This is what makes repeated/scheduled execution safe
 * -- one bad router does not take the whole run down, and each run is
 * independent of the last (no state is carried between invocations).
 *
 * After evaluating, this command also delivers P7's optional
 * notifications (AlertNotifier) for every alert that newly became
 * 'triggered' or 'resolved' during this run -- a no-op unless the host
 * application has configured a mail/webhook route (see
 * config('mikrotik.notifications')) -- and reconciles P10 incidents
 * (IncidentManager) from the same triggered/resolved alerts. Incident
 * transitions currently coincide exactly with these alert transitions
 * (see IncidentManager's docblock for why), so notification timing is
 * unchanged by P10; incident counts are only added to this command's
 * own console output.
 */
class MonitorCommand extends Command
{
    protected $signature = 'mikrotik:monitor
        {--connection=* : Connection name(s) to monitor; omit to monitor every connection in config(mikrotik.connections)}';

    protected $description = 'Record a MikroTik monitoring snapshot and evaluate alert rules against it';

    public function handle(
        SnapshotRecorder $recorder,
        RuleEvaluator $evaluator,
        IncidentManager $incidents,
        AlertNotifier $notifier,
        ConfigRepository $config
    ): int {
        /** @var list<string> $requested */
        $requested = (array) $this->option('connection');
        $names = $requested !== [] ? $requested : array_keys((array) $config->get('mikrotik.connections', []));

        if ($names === []) {
            $this->components->warn('No MikroTik connections are configured (config(mikrotik.connections) is empty).');

            return self::FAILURE;
        }

        $succeeded = 0;

        foreach ($names as $name) {
            try {
                $activeIdsBefore = MikrotikAlert::query()->forConnection($name)->active()->pluck('id');

                $snapshot = $recorder->record($name);
                $triggered = $evaluator->evaluate($snapshot);

                $justResolved = MikrotikAlert::query()
                    ->whereIn('id', $activeIdsBefore->diff(
                        MikrotikAlert::query()->forConnection($name)->active()->pluck('id')
                    ))
                    ->get();

                $transitions = $incidents->reconcile($triggered, $justResolved);

                foreach ([...$triggered, ...$justResolved->all()] as $alert) {
                    $notifier->notify($alert);
                }

                $this->components->info(
                    "[{$name}] snapshot #{$snapshot->id} recorded, " . count($triggered) . ' triggered, '
                        . $justResolved->count() . ' resolved, '
                        . count($transitions['opened']) . ' incident(s) opened, '
                        . count($transitions['resolved']) . ' incident(s) resolved.'
                );
                $succeeded++;
            } catch (MikrotikException $e) {
                $this->components->error("[{$name}] " . $e->getMessage());
            }
        }

        return $succeeded > 0 ? self::SUCCESS : self::FAILURE;
    }
}
