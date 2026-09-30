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

class DhcpLeaseResourceTest extends TestCase
{
    private const PASSWORD = 'super-secret-password';

    private function leases()
    {
        return Mikrotik::connection()->dhcp()->leases();
    }

    public function test_list_returns_a_collection_of_typed_dtos(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server/lease' => Http::response([
            ['.id' => '*1', 'address' => '192.168.1.10', 'mac-address' => 'AA:BB:CC:DD:EE:FF', 'status' => 'bound', 'dynamic' => 'true'],
        ], 200)]);

        $result = $this->leases()->list();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame('192.168.1.10', $result[0]->address);
        $this->assertTrue($result[0]->dynamic);
    }

    public function test_find_returns_a_single_typed_dto(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server/lease/*1' => Http::response(
            ['.id' => '*1', 'address' => '192.168.1.10', 'host-name' => 'my-host'],
            200
        )]);

        $lease = $this->leases()->find('*1');

        $this->assertSame('my-host', $lease->hostName);
    }

    public function test_add_sends_put_for_a_static_reservation(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server/lease' => Http::response(
            ['.id' => '*A', 'address' => '192.168.1.99', 'mac-address' => 'AA:BB:CC:DD:EE:FF'],
            200
        )]);

        $lease = $this->leases()->add(['address' => '192.168.1.99', 'mac-address' => 'AA:BB:CC:DD:EE:FF']);

        $this->assertSame('*A', $lease->id);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['address'] === '192.168.1.99');
    }

    public function test_add_without_address_throws_before_sending_a_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidResourceException::class);

        $this->leases()->add(['mac-address' => 'AA:BB:CC:DD:EE:FF']);
    }

    public function test_update_sends_patch(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server/lease/*1' => Http::response(['.id' => '*1', 'comment' => 'updated'], 200)]);

        $lease = $this->leases()->update('*1', ['comment' => 'updated']);

        $this->assertSame('updated', $lease->comment);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_remove_sends_delete(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server/lease/*1' => Http::response('', 200)]);

        $this->leases()->remove('*1');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_disable_patches_disabled_true(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server/lease/*1' => Http::response(['.id' => '*1', 'disabled' => 'true'], 200)]);

        $this->assertTrue($this->leases()->disable('*1')->disabled);
        Http::assertSent(fn ($r) => $r['disabled'] === 'true');
    }

    public function test_enable_patches_disabled_false(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server/lease/*1' => Http::response(['.id' => '*1', 'disabled' => 'false'], 200)]);

        $this->assertFalse($this->leases()->enable('*1')->disabled);
        Http::assertSent(fn ($r) => $r['disabled'] === 'false');
    }

    public function test_update_never_retries(): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => 3, 'sleep' => 0]);

        $attempts = 0;
        Http::fake(['router.test/rest/ip/dhcp-server/lease/*1' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        try {
            $this->leases()->update('*1', ['comment' => 'x']);
        } catch (RouterOsException) {
        }

        $this->assertSame(1, $attempts);
    }

    public function test_find_rejects_invalid_identifiers_without_sending_a_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidMenuPathException::class);

        $this->leases()->find('..');
    }

    public function test_add_error_response_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server/lease' => Http::response(['error' => 400, 'message' => 'Bad Request'], 400)]);

        $this->expectException(RouterOsException::class);

        $this->leases()->add(['address' => 'bad']);
    }

    public function test_list_results_never_contain_the_password(): void
    {
        Http::fake(['router.test/rest/ip/dhcp-server/lease' => Http::response([
            ['.id' => '*1', 'address' => '192.168.1.10', 'comment' => 'not the password'],
        ], 200)]);

        $result = $this->leases()->list();

        $this->assertStringNotContainsString(self::PASSWORD, json_encode($result->map->toArray()->all()));
    }
}
