<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Easybdit\LaravelMikrotik\Exceptions\InvalidResourceException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class IpPoolResourceTest extends TestCase
{
    private const PASSWORD = 'super-secret-password';

    private function pools()
    {
        return Mikrotik::connection()->ip()->pools();
    }

    public function test_list_returns_a_collection_of_typed_dtos(): void
    {
        Http::fake(['router.test/rest/ip/pool' => Http::response([
            ['.id' => '*1', 'name' => 'pptp-pool', 'ranges' => '192.168.1.10-192.168.1.20'],
        ], 200)]);

        $result = $this->pools()->list();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame('pptp-pool', $result[0]->name);
        $this->assertSame('192.168.1.10-192.168.1.20', $result[0]->ranges);
    }

    public function test_find_returns_a_single_typed_dto(): void
    {
        Http::fake(['router.test/rest/ip/pool/*1' => Http::response(
            ['.id' => '*1', 'name' => 'pptp-pool', 'ranges' => '192.168.1.10-192.168.1.20'],
            200
        )]);

        $pool = $this->pools()->find('*1');

        $this->assertSame('pptp-pool', $pool->name);
    }

    public function test_add_sends_put_and_returns_the_created_pool(): void
    {
        Http::fake(['router.test/rest/ip/pool' => Http::response(
            ['.id' => '*A', 'name' => 'test-pool', 'ranges' => '203.0.113.10-203.0.113.20'],
            200
        )]);

        $pool = $this->pools()->add(['name' => 'test-pool', 'ranges' => '203.0.113.10-203.0.113.20']);

        $this->assertSame('*A', $pool->id);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['name'] === 'test-pool');
    }

    public function test_add_without_name_throws_before_sending_a_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidResourceException::class);

        $this->pools()->add(['ranges' => '192.168.1.10-192.168.1.20']);
    }

    public function test_update_sends_patch(): void
    {
        Http::fake(['router.test/rest/ip/pool/*1' => Http::response(['.id' => '*1', 'ranges' => '192.168.1.10-192.168.1.30'], 200)]);

        $pool = $this->pools()->update('*1', ['ranges' => '192.168.1.10-192.168.1.30']);

        $this->assertSame('192.168.1.10-192.168.1.30', $pool->ranges);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_remove_sends_delete(): void
    {
        Http::fake(['router.test/rest/ip/pool/*1' => Http::response('', 200)]);

        $this->pools()->remove('*1');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_no_enable_or_disable_methods_exist(): void
    {
        // Deliberate: RouterOS's /ip/pool has no `disabled` property
        // (confirmed against a real device) -- see IpPoolResource's
        // docblock. This package does not force an unsupported
        // operation onto a resource.
        $this->assertFalse(method_exists(\Easybdit\LaravelMikrotik\Resources\IpPoolResource::class, 'enable'));
        $this->assertFalse(method_exists(\Easybdit\LaravelMikrotik\Resources\IpPoolResource::class, 'disable'));
    }

    public function test_update_never_retries(): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => 3, 'sleep' => 0]);

        $attempts = 0;
        Http::fake(['router.test/rest/ip/pool/*1' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        try {
            $this->pools()->update('*1', ['ranges' => 'x']);
        } catch (RouterOsException) {
        }

        $this->assertSame(1, $attempts);
    }

    public function test_find_rejects_invalid_identifiers_without_sending_a_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidMenuPathException::class);

        $this->pools()->find('..');
    }

    public function test_add_error_response_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/ip/pool' => Http::response(['error' => 400, 'message' => 'Bad Request'], 400)]);

        $this->expectException(RouterOsException::class);

        $this->pools()->add(['name' => 'bad']);
    }

    public function test_list_results_never_contain_the_password(): void
    {
        Http::fake(['router.test/rest/ip/pool' => Http::response([
            ['.id' => '*1', 'name' => 'test-pool', 'ranges' => '192.168.1.10-192.168.1.20'],
        ], 200)]);

        $result = $this->pools()->list();

        $this->assertStringNotContainsString(self::PASSWORD, json_encode($result->map->toArray()->all()));
    }
}
