<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests;

use Easybdit\LaravelMikrotik\MikrotikServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Any HTTP call that doesn't match a registered Http::fake() pattern
        // must fail the test immediately (StrayRequestException) instead of
        // silently falling through to a real network request. Without this,
        // a typo'd fake URL degrades into a slow, flaky, network-dependent
        // test instead of an obvious failure.
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [MikrotikServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mikrotik.default', 'default');
        $app['config']->set('mikrotik.connections.default', [
            'transport'  => 'rest',
            'host'       => 'router.test',
            'port'       => 443,
            'username'   => 'admin',
            'password'   => 'super-secret-password',
            'verify_tls' => true,
            'timeout'    => 5,
        ]);
    }

    // Only takes effect for tests using RefreshDatabase — orchestra/
    // testbench only calls this hook then, so P1-P3 tests (which use no
    // database at all) are unaffected.
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
