<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class InterfaceResourceTest extends TestCase
{
    private const PASSWORD = 'super-secret-password'; // matches TestCase::defineEnvironment()

    private function interfaces()
    {
        return Mikrotik::connection()->interface();
    }

    public function test_list_returns_a_collection_of_typed_dtos(): void
    {
        Http::fake(['router.test/rest/interface' => Http::response([
            ['.id' => '*1', 'name' => 'ether1', 'type' => 'ether', 'running' => 'true', 'disabled' => 'false'],
            ['.id' => '*7', 'name' => 'ether7', 'type' => 'ether', 'running' => 'false', 'disabled' => 'false'],
        ], 200)]);

        $result = $this->interfaces()->list();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
        $this->assertSame('ether1', $result[0]->name);
        $this->assertTrue($result[0]->running);
        $this->assertFalse($result[1]->running);
    }

    public function test_list_sends_simple_equality_filters(): void
    {
        Http::fake(['router.test/rest/interface*' => Http::response([
            ['.id' => '*7', 'name' => 'ether7', 'type' => 'ether'],
        ], 200)]);

        $result = $this->interfaces()->list(['type' => 'ether']);

        $this->assertCount(1, $result);
        Http::assertSent(fn ($r) => $r->url() === 'https://router.test/rest/interface?type=ether');
    }

    public function test_find_returns_a_single_typed_dto(): void
    {
        Http::fake(['router.test/rest/interface/ether7' => Http::response(
            ['.id' => '*7', 'name' => 'ether7', 'type' => 'ether', 'running' => 'false', 'disabled' => 'false', 'comment' => 'idle test port'],
            200
        )]);

        $interface = $this->interfaces()->find('ether7');

        $this->assertSame('*7', $interface->id);
        $this->assertSame('ether7', $interface->name);
        $this->assertSame('idle test port', $interface->comment);
    }

    public function test_find_accepts_a_dot_id_identifier_too(): void
    {
        Http::fake(['router.test/rest/interface/*7' => Http::response(
            ['.id' => '*7', 'name' => 'ether7'],
            200
        )]);

        $interface = $this->interfaces()->find('*7');

        $this->assertSame('ether7', $interface->name);
    }

    public function test_update_sends_patch_with_the_id_in_the_path(): void
    {
        Http::fake(['router.test/rest/interface/ether7' => Http::response(
            ['.id' => '*7', 'name' => 'ether7', 'comment' => 'updated'],
            200
        )]);

        $interface = $this->interfaces()->update('ether7', ['comment' => 'updated']);

        $this->assertSame('updated', $interface->comment);

        Http::assertSent(function ($r) {
            return $r->method() === 'PATCH'
                && $r->url() === 'https://router.test/rest/interface/ether7'
                && $r['comment'] === 'updated';
        });
    }

    public function test_enable_patches_disabled_false(): void
    {
        Http::fake(['router.test/rest/interface/ether7' => Http::response(['.id' => '*7', 'disabled' => 'false'], 200)]);

        $interface = $this->interfaces()->enable('ether7');

        $this->assertFalse($interface->disabled);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['disabled'] === 'false');
    }

    public function test_disable_patches_disabled_true(): void
    {
        Http::fake(['router.test/rest/interface/ether7' => Http::response(['.id' => '*7', 'disabled' => 'true'], 200)]);

        $interface = $this->interfaces()->disable('ether7');

        $this->assertTrue($interface->disabled);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['disabled'] === 'true');
    }

    public function test_update_error_response_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/interface/ether7' => Http::response(
            ['error' => 400, 'message' => 'Bad Request', 'detail' => 'invalid value'],
            400
        )]);

        $this->expectException(RouterOsException::class);

        $this->interfaces()->update('ether7', ['comment' => 'x']);
    }

    public function test_find_not_found_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/interface/ether99' => Http::response(
            ['error' => 404, 'message' => 'Not Found'],
            404
        )]);

        $this->expectException(RouterOsException::class);

        $this->interfaces()->find('ether99');
    }

    public function test_update_never_retries_even_when_the_connection_has_retry_configured(): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => 3, 'sleep' => 0]);

        $attempts = 0;
        Http::fake(['router.test/rest/interface/ether7' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        try {
            $this->interfaces()->update('ether7', ['comment' => 'x']);
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
            'slash' => ['ether7/etc'],
            'protocol-like' => ['http://evil.test'],
            'query string' => ['ether7?evil=1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIdentifierProvider')]
    public function test_find_rejects_invalid_identifiers_without_sending_a_request(string $id): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidMenuPathException::class);

        $this->interfaces()->find($id);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIdentifierProvider')]
    public function test_update_rejects_invalid_identifiers_without_sending_a_request(string $id): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidMenuPathException::class);

        $this->interfaces()->update($id, ['comment' => 'x']);
    }

    public function test_no_add_or_remove_methods_exist(): void
    {
        // Documented as a deliberate scope decision (see InterfaceResource's
        // docblock): RouterOS REST does not confirm PUT/DELETE support for
        // /interface, and physical interfaces generally cannot be
        // created/destroyed through this generic menu.
        $this->assertFalse(method_exists(\Easybdit\LaravelMikrotik\Resources\InterfaceResource::class, 'add'));
        $this->assertFalse(method_exists(\Easybdit\LaravelMikrotik\Resources\InterfaceResource::class, 'remove'));
    }

    public function test_exceptions_never_contain_the_password(): void
    {
        Http::fake(['router.test/rest/interface/ether7' => Http::response(
            ['error' => 400, 'message' => 'Bad Request', 'detail' => 'invalid value'],
            400
        )]);

        try {
            $this->interfaces()->update('ether7', ['comment' => 'bad']);
            $this->fail('Expected RouterOsException was not thrown.');
        } catch (RouterOsException $e) {
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, (string) $e->getRouterOsDetail());
        }
    }

    public function test_list_results_never_contain_the_password(): void
    {
        Http::fake(['router.test/rest/interface' => Http::response([
            ['.id' => '*7', 'name' => 'ether7', 'comment' => 'not the password'],
        ], 200)]);

        $result = $this->interfaces()->list();

        $this->assertStringNotContainsString(self::PASSWORD, json_encode($result->map->toArray()->all()));
    }
}
