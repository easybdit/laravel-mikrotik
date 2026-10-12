<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Connection\ConnectionManager;
use Easybdit\LaravelMikrotik\Exceptions\AuthenticationException;
use Easybdit\LaravelMikrotik\Exceptions\InvalidConfigurationException;
use Easybdit\LaravelMikrotik\Models\MikrotikSnapshot;
use Easybdit\LaravelMikrotik\Monitoring\SnapshotRecorder;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

// P24. HTTP-fake tests only; no benchmark or real-device performance claim.
class MultiRouterPollTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        foreach (['b' => 'b.test', 'c' => 'c.test'] as $name => $host) {
            $app['config']->set("mikrotik.connections.$name", ['transport' => 'rest', 'host' => $host, 'port' => 443,
                'username' => 'admin', 'password' => 'pw', 'verify_tls' => true, 'timeout' => 5]);
        }
    }

    private function okFor(string $host): array
    {
        return [
            "$host/rest/system/resource" => Http::response(['uptime' => '1d', 'version' => '7.10.2'], 200),
            "$host/rest/system/health" => Http::response([], 200),
            "$host/rest/interface" => Http::response([], 200),
        ];
    }

    public function test_one_failing_router_does_not_stop_the_others_and_order_is_preserved(): void
    {
        Http::fake($this->okFor('router.test') + $this->okFor('c.test') + ['b.test/*' => Http::response('', 401)]);

        $results = app(ConnectionManager::class)->pollMany(['c', 'b', 'default', 'c']);

        $this->assertSame(['c', 'b', 'default'], array_keys($results));
        $this->assertTrue($results['c']->ok());
        $this->assertTrue($results['default']->ok());
        $this->assertFalse($results['b']->ok());
        $this->assertInstanceOf(AuthenticationException::class, $results['b']->error);
        $this->assertStringNotContainsString('pw', $results['b']->error->getMessage());
    }

    public function test_unknown_connection_is_isolated_and_defaults_cover_all_configured(): void
    {
        Http::fake($this->okFor('router.test') + $this->okFor('b.test') + $this->okFor('c.test'));
        $m = app(ConnectionManager::class);

        $this->assertSame(['default', 'b', 'c'], array_keys($m->pollMany()));
        $r = $m->pollMany(['nope', 'b']);
        $this->assertInstanceOf(InvalidConfigurationException::class, $r['nope']->error);
        $this->assertTrue($r['b']->ok());
    }

    public function test_record_many_persists_only_successful_routers(): void
    {
        Http::fake($this->okFor('router.test') + ['b.test/*' => Http::response('', 401)] + $this->okFor('c.test'));

        $out = app(SnapshotRecorder::class)->recordMany(['default', 'b', 'c']);

        $this->assertInstanceOf(MikrotikSnapshot::class, $out['default']);
        $this->assertInstanceOf(AuthenticationException::class, $out['b']);
        $this->assertSame(2, MikrotikSnapshot::query()->count());
    }

    public function test_single_record_is_unchanged(): void
    {
        Http::fake($this->okFor('router.test'));

        $this->assertSame('default', app(SnapshotRecorder::class)->record()->connection);
    }
}
