<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\AuthenticationException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * P24: RouterConnection::pollAll() / Transport::getMany() — concurrent
 * reads via Http::pool(), used internally by SnapshotRecorder. Every
 * test here exercises the concurrent path directly; SnapshotTest
 * already covers that record() still produces the correct
 * MikrotikSnapshot end to end.
 */
class ConcurrentReadTest extends TestCase
{
    private const PASSWORD = 'super-secret-password'; // matches TestCase::defineEnvironment()

    private function fakeAllThree(array $overrides = []): void
    {
        Http::fake(array_merge([
            'router.test/rest/system/resource' => Http::response([['cpu-load' => '5', 'version' => '7.15']], 200),
            'router.test/rest/system/health'   => Http::response([], 200),
            'router.test/rest/interface'       => Http::response([
                ['name' => 'ether1', 'type' => 'ether'],
            ], 200),
        ], $overrides));
    }

    public function test_poll_all_returns_the_same_typed_dtos_as_the_individual_methods(): void
    {
        $this->fakeAllThree();

        $data = Mikrotik::connection()->pollAll();

        $this->assertSame('5', (string) $data['resource']->cpuLoad);
        $this->assertSame('7.15', $data['resource']->version);
        $this->assertNotNull($data['health']);
        $this->assertSame(1, $data['interfaces']->count());
        $this->assertSame('ether1', $data['interfaces']->get('ether1')->name);
    }

    public function test_poll_all_sends_all_three_requests_concurrently_via_one_pool(): void
    {
        $this->fakeAllThree();

        Mikrotik::connection()->pollAll();

        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://router.test/rest/system/resource');
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://router.test/rest/system/health');
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://router.test/rest/interface');
    }

    public function test_poll_all_throws_router_os_exception_when_one_path_errors(): void
    {
        $this->fakeAllThree([
            'router.test/rest/system/health' => Http::response(['error' => 400, 'message' => 'Bad Request'], 400),
        ]);

        $this->expectException(RouterOsException::class);

        Mikrotik::connection()->pollAll();
    }

    public function test_poll_all_throws_authentication_exception_on_401(): void
    {
        $this->fakeAllThree([
            'router.test/rest/interface' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $this->expectException(AuthenticationException::class);

        Mikrotik::connection()->pollAll();
    }

    public function test_poll_all_respects_opt_in_retry_per_pooled_request(): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => 3, 'sleep' => 0]);

        $healthAttempts = 0;
        Http::fake([
            'router.test/rest/system/resource' => Http::response([['cpu-load' => '5']], 200),
            'router.test/rest/system/health'   => function () use (&$healthAttempts) {
                $healthAttempts++;

                return Http::response(['message' => 'Internal Server Error'], 500);
            },
            'router.test/rest/interface' => Http::response([], 200),
        ]);

        try {
            Mikrotik::connection()->pollAll();
        } catch (RouterOsException) {
            // expected -- this test only cares about the attempt count.
        }

        $this->assertSame(3, $healthAttempts, 'a pooled request should retry exactly like a single get() already does');
    }

    public function test_poll_all_does_not_retry_by_default(): void
    {
        $healthAttempts = 0;
        Http::fake([
            'router.test/rest/system/resource' => Http::response([['cpu-load' => '5']], 200),
            'router.test/rest/system/health'   => function () use (&$healthAttempts) {
                $healthAttempts++;

                return Http::response(['message' => 'Internal Server Error'], 500);
            },
            'router.test/rest/interface' => Http::response([], 200),
        ]);

        try {
            Mikrotik::connection()->pollAll();
        } catch (RouterOsException) {
        }

        $this->assertSame(1, $healthAttempts);
    }

    public function test_poll_all_results_never_contain_the_password(): void
    {
        $this->fakeAllThree([
            'router.test/rest/interface' => Http::response([
                ['name' => 'ether1', 'comment' => 'not the password'],
            ], 200),
        ]);

        $data = Mikrotik::connection()->pollAll();

        $this->assertStringNotContainsString(self::PASSWORD, json_encode($data['resource']->toArray()));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($data['interfaces']->toArray()));
    }

    public function test_poll_all_exceptions_never_contain_the_password(): void
    {
        $this->fakeAllThree([
            'router.test/rest/system/health' => Http::response(['error' => 400, 'message' => 'Bad Request', 'detail' => 'x'], 400),
        ]);

        try {
            Mikrotik::connection()->pollAll();
            $this->fail('Expected RouterOsException was not thrown.');
        } catch (RouterOsException $e) {
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, (string) $e->getRouterOsDetail());
        }
    }
}
