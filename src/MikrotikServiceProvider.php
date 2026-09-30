<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik;

use Easybdit\LaravelMikrotik\Connection\ConnectionManager;
use Easybdit\LaravelMikrotik\Console\Commands\MonitorCommand;
use Easybdit\LaravelMikrotik\Monitoring\RuleEvaluator;
use Easybdit\LaravelMikrotik\Monitoring\SnapshotRecorder;
use Illuminate\Support\ServiceProvider;

class MikrotikServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mikrotik.php', 'mikrotik');

        $this->app->singleton('mikrotik', function ($app) {
            return new ConnectionManager($app->make('config'));
        });

        $this->app->alias('mikrotik', ConnectionManager::class);

        $this->app->singleton(SnapshotRecorder::class, function ($app) {
            return new SnapshotRecorder($app->make(ConnectionManager::class));
        });

        $this->app->singleton(RuleEvaluator::class, function () {
            return new RuleEvaluator();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MonitorCommand::class]);

            $this->publishes([
                __DIR__ . '/../config/mikrotik.php' => $this->app->configPath('mikrotik.php'),
            ], 'mikrotik-config');

            // Opt-in: monitoring snapshot persistence (P4) and rules/
            // alerts (P5). An application that never publishes+runs these
            // migrations is unaffected — nothing in P1-P3 requires them.
            $this->publishes([
                __DIR__ . '/../database/migrations/2024_01_01_000000_create_mikrotik_snapshots_table.php'
                    => $this->app->databasePath('migrations/2024_01_01_000000_create_mikrotik_snapshots_table.php'),
                __DIR__ . '/../database/migrations/2024_01_01_000001_create_mikrotik_rules_table.php'
                    => $this->app->databasePath('migrations/2024_01_01_000001_create_mikrotik_rules_table.php'),
                __DIR__ . '/../database/migrations/2024_01_01_000002_create_mikrotik_alerts_table.php'
                    => $this->app->databasePath('migrations/2024_01_01_000002_create_mikrotik_alerts_table.php'),
            ], 'mikrotik-migrations');
        }
    }

    public function provides(): array
    {
        return ['mikrotik', ConnectionManager::class, SnapshotRecorder::class, RuleEvaluator::class];
    }
}
