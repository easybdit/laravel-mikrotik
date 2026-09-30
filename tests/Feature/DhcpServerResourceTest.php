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

class DhcpServerResourceTest extends TestCase
{
    private const PASSWORD = 'super-secret-password';

    private function servers()
    {
        return Mikrotik::connection()->dhcp()->servers();
    }

    public function test_list_returns_a_collection_of_typed_dtos(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server' => Http::response([
            ['.id' => '*1', 'name' => 'dhcp1', 'interface' => 'bridge1', 'disabled' => 'false'],
        ], 200)]);

        $result = $this->servers()->list();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame('dhcp1', $result[0]->name);
        $this->assertFalse($result[0]->disabled);
    }

    public function test_find_returns_a_single_typed_dto(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server/*1' => Http::response(
            ['.id' => '*1', 'name' => 'dhcp1', 'address-pool' => 'pool1', 'lease-time' => '1d'],
            200
        )]);

        $server = $this->servers()->find('*1');

        $this->assertSame('dhcp1', $server->name);
        $this->assertSame('pool1', $server->addressPool);
        $this->assertSame('1d', $server->leaseTime);
    }

    public function test_add_sends_put_and_returns_the_created_server(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server' => Http::response(
            ['.id' => '*A', 'name' => 'dhcp2', 'interface' => 'bridge2'],
            200
        )]);

        $server = $this->servers()->add(['name' => 'dhcp2', 'interface' => 'bridge2']);

        $this->assertSame('*A', $server->id);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['name'] === 'dhcp2');
    }

    public function test_add_without_name_throws_before_sending_a_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidResourceException::class);

        $this->servers()->add(['interface' => 'bridge1']);
    }

    public function test_update_sends_patch(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server/*1' => Http::response(['.id' => '*1', 'comment' => 'updated'], 200)]);

        $server = $this->servers()->update('*1', ['comment' => 'updated']);

        $this->assertSame('updated', $server->comment);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_remove_sends_delete(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server/*1' => Http::response('', 200)]);

        $this->servers()->remove('*1');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_enable_and_disable_patch_the_disabled_field(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server/*1' => function ($request) {
            return Http::response(['.id' => '*1', 'disabled' => (string) json_decode($request->body())->disabled], 200);
        }]);

        $this->assertTrue($this->servers()->disable('*1')->disabled);
        $this->assertFalse($this->servers()->enable('*1')->disabled);
    }

    public function test_add_error_response_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server' => Http::response(['error' => 400, 'message' => 'Bad Request'], 400)]);

        $this->expectException(RouterOsException::class);

        $this->servers()->add(['name' => 'bad']);
    }

    public function test_update_never_retries(): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => 3, 'sleep' => 0]);

        $attempts = 0;
        Http::fake(['router.test/rest/ip/dhcp-server/*1' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        try {
            $this->servers()->update('*1', ['comment' => 'x']);
        } catch (RouterOsException) {
        }

        $this->assertSame(1, $attempts);
    }

    public function test_find_rejects_invalid_identifiers_without_sending_a_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidMenuPathException::class);

        $this->servers()->find('..');
    }

    public function test_exceptions_never_contain_the_password(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server' => Http::response(['error' => 400, 'message' => 'Bad Request', 'detail' => 'x'], 400)]);

        try {
            $this->servers()->add(['name' => 'bad']);
            $this->fail('Expected RouterOsException was not thrown.');
        } catch (RouterOsException $e) {
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }
}
