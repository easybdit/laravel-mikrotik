<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests;

use Easybdit\LaravelMikrotik\MikrotikServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
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
}
