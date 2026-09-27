<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik;

use Easybdit\LaravelMikrotik\Connection\ConnectionManager;
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
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/mikrotik.php' => $this->app->configPath('mikrotik.php'),
            ], 'mikrotik-config');
        }
    }

    public function provides(): array
    {
        return ['mikrotik', ConnectionManager::class];
    }
}
