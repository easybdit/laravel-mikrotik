<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Console\Commands;

use Easybdit\LaravelMikrotik\Exceptions\MikrotikException;
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
 */
class MonitorCommand extends Command
{
    protected $signature = 'mikrotik:monitor
        {--connection=* : Connection name(s) to monitor; omit to monitor every connection in config(mikrotik.connections)}';

    protected $description = 'Record a MikroTik monitoring snapshot and evaluate alert rules against it';

    public function handle(SnapshotRecorder $recorder, RuleEvaluator $evaluator, ConfigRepository $config): int
    {
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
                $snapshot = $recorder->record($name);
                $alerts = $evaluator->evaluate($snapshot);

                $this->components->info(
                    "[{$name}] snapshot #{$snapshot->id} recorded, " . count($alerts) . ' alert(s) triggered.'
                );
                $succeeded++;
            } catch (MikrotikException $e) {
                $this->components->error("[{$name}] " . $e->getMessage());
            }
        }

        return $succeeded > 0 ? self::SUCCESS : self::FAILURE;
    }
}
