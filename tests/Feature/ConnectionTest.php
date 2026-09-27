<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\AuthenticationException;
use Easybdit\LaravelMikrotik\Exceptions\ConnectionException as MikrotikConnectionException;
use Easybdit\LaravelMikrotik\Exceptions\InvalidConfigurationException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

class ConnectionTest extends TestCase
{
    public function test_an_unfaked_request_fails_fast_instead_of_hitting_the_network(): void
    {
        // Regression guard for Http::preventStrayRequests() (set in
        // TestCase::setUp()): a fake URL pattern that doesn't match the
        // actual request must never silently fall through to a real
        // network call — it previously did, and every test in this suite
        // passed for the wrong reason until the fake patterns were fixed.
        Http::fake([
            'router.test/rest/system/health' => Http::response([], 200),
        ]);

        $this->expectException(StrayRequestException::class);

        Mikrotik::connection()->resource();
    }

    public function test_it_resolves_the_default_named_connection(): void
    {
        Http::fake([
            'router.test/rest/system/resource' => Http::response([
                ['architecture-name' => 'arm', 'version' => '7.15'],
            ], 200),
        ]);

        $connection = Mikrotik::connection();

        $this->assertSame('default', $connection->name());
        $this->assertSame('7.15', $connection->resource()->version);
    }

    public function test_it_resolves_an_explicitly_named_connection(): void
    {
        config()->set('mikrotik.connections.branch-01', [
            'transport' => 'rest',
            'host' => 'branch01.test',
            'username' => 'admin',
            'password' => 'x',
        ]);

        Http::fake([
            'branch01.test/rest/system/resource' => Http::response([['version' => '7.16']], 200),
        ]);

        $connection = Mikrotik::connection('branch-01');

        $this->assertSame('branch-01', $connection->name());
        $this->assertSame('7.16', $connection->resource()->version);
    }

    public function test_unknown_connection_name_throws(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        Mikrotik::connection('does-not-exist');
    }

    public function test_authentication_failure_throws_authentication_exception(): void
    {
        Http::fake([
            'router.test/rest/system/resource' => Http::response(
                ['error' => 401, 'message' => 'Unauthorized'],
                401
            ),
        ]);

        $this->expectException(AuthenticationException::class);

        Mikrotik::connection()->resource();
    }

    public function test_unreachable_router_throws_connection_exception(): void
    {
        Http::fake([
            'router.test/*' => function () {
                throw new HttpConnectionException('cURL error 7: Failed to connect to router.test port 443: Connection refused');
            },
        ]);

        $this->expectException(MikrotikConnectionException::class);

        Mikrotik::connection()->resource();
    }

    public function test_timeout_throws_connection_exception(): void
    {
        Http::fake([
            'router.test/*' => function () {
                throw new HttpConnectionException('cURL error 28: Operation timed out after 5000 milliseconds');
            },
        ]);

        $this->expectException(MikrotikConnectionException::class);

        Mikrotik::connection()->resource();
    }
}
