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

class IpRouteResourceTest extends TestCase
{
    private const PASSWORD = 'super-secret-password';

    private function routes()
    {
        return Mikrotik::connection()->ip()->routes();
    }

    public function test_list_returns_a_collection_of_typed_dtos(): void
    {
        Http::fake(['router.test/rest/ip/route' => Http::response([
            ['.id' => '*1', 'dst-address' => '0.0.0.0/0', 'gateway' => '192.168.1.1', 'distance' => '1', 'inactive' => 'false'],
        ], 200)]);

        $result = $this->routes()->list();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame('0.0.0.0/0', $result[0]->dstAddress);
        $this->assertSame(1, $result[0]->distance);
        $this->assertFalse($result[0]->inactive);
    }

    public function test_find_returns_a_single_typed_dto(): void
    {
        Http::fake(['router.test/rest/ip/route/*1' => Http::response(
            ['.id' => '*1', 'dst-address' => '203.0.113.0/24', 'gateway' => '192.168.1.254'],
            200
        )]);

        $route = $this->routes()->find('*1');

        $this->assertSame('203.0.113.0/24', $route->dstAddress);
        $this->assertSame('192.168.1.254', $route->gateway);
    }

    public function test_add_sends_put_and_returns_the_created_route(): void
    {
        Http::fake(['router.test/rest/ip/route' => Http::response(
            ['.id' => '*A', 'dst-address' => '203.0.113.0/24', 'gateway' => '192.168.1.254'],
            200
        )]);

        $route = $this->routes()->add(['dst-address' => '203.0.113.0/24', 'gateway' => '192.168.1.254']);

        $this->assertSame('*A', $route->id);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['dst-address'] === '203.0.113.0/24');
    }

    public function test_add_without_dst_address_throws_before_sending_a_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidResourceException::class);

        $this->routes()->add(['gateway' => '192.168.1.254']);
    }

    public function test_update_sends_patch(): void
    {
        Http::fake(['router.test/rest/ip/route/*1' => Http::response(['.id' => '*1', 'comment' => 'updated'], 200)]);

        $route = $this->routes()->update('*1', ['comment' => 'updated']);

        $this->assertSame('updated', $route->comment);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_remove_sends_delete(): void
    {
        Http::fake(['router.test/rest/ip/route/*1' => Http::response('', 200)]);

        $this->routes()->remove('*1');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_enable_and_disable_patch_the_disabled_field(): void
    {
        Http::fake(['router.test/rest/ip/route/*1' => Http::response(['.id' => '*1', 'disabled' => 'true'], 200)]);

        $this->assertTrue($this->routes()->disable('*1')->disabled);
        Http::assertSent(fn ($r) => $r['disabled'] === 'true');
    }

    public function test_update_never_retries(): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => 3, 'sleep' => 0]);

        $attempts = 0;
        Http::fake(['router.test/rest/ip/route/*1' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        try {
            $this->routes()->update('*1', ['comment' => 'x']);
        } catch (RouterOsException) {
        }

        $this->assertSame(1, $attempts);
    }

    public function test_find_rejects_invalid_identifiers_without_sending_a_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidMenuPathException::class);

        $this->routes()->find('..');
    }

    public function test_add_error_response_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/ip/route' => Http::response(['error' => 400, 'message' => 'Bad Request'], 400)]);

        $this->expectException(RouterOsException::class);

        $this->routes()->add(['dst-address' => 'bad']);
    }

    public function test_list_results_never_contain_the_password(): void
    {
        Http::fake(['router.test/rest/ip/route' => Http::response([
            ['.id' => '*1', 'dst-address' => '203.0.113.0/24', 'comment' => 'not the password'],
        ], 200)]);

        $result = $this->routes()->list();

        $this->assertStringNotContainsString(self::PASSWORD, json_encode($result->map->toArray()->all()));
    }
}
