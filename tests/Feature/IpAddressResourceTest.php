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

class IpAddressResourceTest extends TestCase
{
    private const PASSWORD = 'super-secret-password'; // matches TestCase::defineEnvironment()

    private function addresses()
    {
        return Mikrotik::connection()->ip()->addresses();
    }

    public function test_list_returns_a_collection_of_typed_dtos(): void
    {
        Http::fake(['router.test/rest/ip/address' => Http::response([
            ['.id' => '*1', 'address' => '192.168.88.1/24', 'interface' => 'bridge1', 'disabled' => 'false'],
            ['.id' => '*2', 'address' => '10.0.0.1/24', 'interface' => 'ether2', 'disabled' => 'true'],
        ], 200)]);

        $result = $this->addresses()->list();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
        $this->assertSame('192.168.88.1/24', $result[0]->address);
        $this->assertFalse($result[0]->disabled);
        $this->assertTrue($result[1]->disabled);
    }

    public function test_list_sends_simple_equality_filters(): void
    {
        Http::fake(['router.test/rest/ip/address*' => Http::response([
            ['.id' => '*2', 'address' => '10.0.0.1/24', 'interface' => 'ether2'],
        ], 200)]);

        $result = $this->addresses()->list(['interface' => 'ether2']);

        $this->assertCount(1, $result);
        Http::assertSent(fn ($r) => $r->url() === 'https://router.test/rest/ip/address?interface=ether2');
    }

    public function test_find_returns_a_single_typed_dto(): void
    {
        Http::fake(['router.test/rest/ip/address/*1' => Http::response(
            ['.id' => '*1', 'address' => '192.168.88.1/24', 'network' => '192.168.88.0', 'interface' => 'bridge1', 'comment' => 'lan'],
            200
        )]);

        $address = $this->addresses()->find('*1');

        $this->assertSame('*1', $address->id);
        $this->assertSame('192.168.88.1/24', $address->address);
        $this->assertSame('192.168.88.0', $address->network);
        $this->assertSame('lan', $address->comment);
    }

    public function test_add_sends_put_with_the_body_and_returns_the_created_record(): void
    {
        Http::fake(['router.test/rest/ip/address' => Http::response(
            ['.id' => '*A', 'address' => '192.168.111.111/32', 'interface' => 'dummy'],
            200
        )]);

        $address = $this->addresses()->add(['address' => '192.168.111.111/32', 'interface' => 'dummy']);

        $this->assertSame('*A', $address->id);
        $this->assertSame('192.168.111.111/32', $address->address);

        Http::assertSent(function ($r) {
            return $r->method() === 'PUT'
                && $r->url() === 'https://router.test/rest/ip/address'
                && $r['address'] === '192.168.111.111/32'
                && $r['interface'] === 'dummy';
        });
    }

    public function test_add_without_address_throws_before_sending_a_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidResourceException::class);

        $this->addresses()->add(['interface' => 'dummy']);
    }

    public function test_update_sends_patch_with_the_id_in_the_path(): void
    {
        Http::fake(['router.test/rest/ip/address/*1' => Http::response(
            ['.id' => '*1', 'address' => '192.168.88.1/24', 'comment' => 'updated'],
            200
        )]);

        $address = $this->addresses()->update('*1', ['comment' => 'updated']);

        $this->assertSame('updated', $address->comment);

        Http::assertSent(function ($r) {
            return $r->method() === 'PATCH'
                && $r->url() === 'https://router.test/rest/ip/address/*1'
                && $r['comment'] === 'updated';
        });
    }

    public function test_remove_sends_delete_with_no_body(): void
    {
        Http::fake(['router.test/rest/ip/address/*1' => Http::response('', 200)]);

        $this->addresses()->remove('*1');

        Http::assertSent(function ($r) {
            return $r->method() === 'DELETE'
                && $r->url() === 'https://router.test/rest/ip/address/*1'
                && $r->body() === '';
        });
    }

    public function test_remove_handles_the_documented_empty_success_body_without_a_malformed_response_error(): void
    {
        // help.mikrotik.com "REST API": "If the deletion has been
        // succeeded, the server responds with an empty response."
        Http::fake(['router.test/rest/ip/address/*1' => Http::response('', 200)]);

        $this->addresses()->remove('*1'); // must not throw MalformedResponseException
        $this->assertTrue(true);
    }

    public function test_enable_patches_disabled_false(): void
    {
        Http::fake(['router.test/rest/ip/address/*1' => Http::response(['.id' => '*1', 'disabled' => 'false'], 200)]);

        $address = $this->addresses()->enable('*1');

        $this->assertFalse($address->disabled);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['disabled'] === 'false');
    }

    public function test_disable_patches_disabled_true(): void
    {
        Http::fake(['router.test/rest/ip/address/*1' => Http::response(['.id' => '*1', 'disabled' => 'true'], 200)]);

        $address = $this->addresses()->disable('*1');

        $this->assertTrue($address->disabled);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['disabled'] === 'true');
    }

    public function test_add_error_response_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/ip/address' => Http::response(
            ['error' => 400, 'message' => 'Bad Request', 'detail' => 'invalid address'],
            400
        )]);

        $this->expectException(RouterOsException::class);

        $this->addresses()->add(['address' => 'not-an-address']);
    }

    public function test_find_not_found_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/ip/address/*99' => Http::response(
            ['error' => 404, 'message' => 'Not Found'],
            404
        )]);

        $this->expectException(RouterOsException::class);

        $this->addresses()->find('*99');
    }

    public function test_update_never_retries_even_when_the_connection_has_retry_configured(): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => 3, 'sleep' => 0]);

        $attempts = 0;
        Http::fake(['router.test/rest/ip/address/*1' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        try {
            $this->addresses()->update('*1', ['comment' => 'x']);
        } catch (RouterOsException) {
            // expected.
        }

        $this->assertSame(1, $attempts);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidIdentifierProvider(): array
    {
        return [
            'empty' => [''],
            'traversal' => ['..'],
            'slash' => ['*1/etc'],
            'protocol-like' => ['http://evil.test'],
            'query string' => ['*1?evil=1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIdentifierProvider')]
    public function test_find_rejects_invalid_identifiers_without_sending_a_request(string $id): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidMenuPathException::class);

        $this->addresses()->find($id);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIdentifierProvider')]
    public function test_remove_rejects_invalid_identifiers_without_sending_a_request(string $id): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidMenuPathException::class);

        $this->addresses()->remove($id);
    }

    public function test_exceptions_never_contain_the_password(): void
    {
        Http::fake(['router.test/rest/ip/address' => Http::response(
            ['error' => 400, 'message' => 'Bad Request', 'detail' => 'invalid address'],
            400
        )]);

        try {
            $this->addresses()->add(['address' => 'bad']);
            $this->fail('Expected RouterOsException was not thrown.');
        } catch (RouterOsException $e) {
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, (string) $e->getRouterOsDetail());
        }
    }

    public function test_list_results_never_contain_the_password(): void
    {
        Http::fake(['router.test/rest/ip/address' => Http::response([
            ['.id' => '*1', 'address' => '192.168.88.1/24', 'comment' => 'not the password'],
        ], 200)]);

        $result = $this->addresses()->list();

        $this->assertStringNotContainsString(self::PASSWORD, json_encode($result->map->toArray()->all()));
    }
}
