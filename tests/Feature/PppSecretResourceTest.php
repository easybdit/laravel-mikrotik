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

class PppSecretResourceTest extends TestCase
{
    private const PASSWORD = 'super-secret-password'; // router credential (TestCase::defineEnvironment())
    private const SECRET_PASSWORD = 'ppp-test-secret-DO-NOT-USE'; // a fake PPP secret password, used only in fake responses

    private function secrets()
    {
        return Mikrotik::connection()->ppp()->secrets();
    }

    public function test_list_returns_a_collection_of_typed_dtos(): void
    {
        Http::fake(['router.test/rest/ppp/secret' => Http::response([
            ['.id' => '*1', 'name' => 'testuser', 'service' => 'pppoe', 'password' => self::SECRET_PASSWORD, 'disabled' => 'false'],
        ], 200)]);

        $result = $this->secrets()->list();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame('testuser', $result[0]->name);
        $this->assertSame('pppoe', $result[0]->service);
    }

    public function test_find_returns_a_single_typed_dto(): void
    {
        Http::fake(['router.test/rest/ppp/secret/*1' => Http::response(
            ['.id' => '*1', 'name' => 'testuser', 'profile' => 'default', 'password' => self::SECRET_PASSWORD],
            200
        )]);

        $secret = $this->secrets()->find('*1');

        $this->assertSame('testuser', $secret->name);
        $this->assertSame('default', $secret->profile);
    }

    public function test_dto_never_exposes_the_secret_password(): void
    {
        Http::fake(['router.test/rest/ppp/secret/*1' => Http::response(
            ['.id' => '*1', 'name' => 'testuser', 'password' => self::SECRET_PASSWORD],
            200
        )]);

        $secret = $this->secrets()->find('*1');

        $this->assertFalse(property_exists($secret, 'password'));
        $this->assertArrayNotHasKey('password', $secret->raw);
        $this->assertStringNotContainsString(self::SECRET_PASSWORD, json_encode($secret));
        $this->assertStringNotContainsString(self::SECRET_PASSWORD, json_encode($secret->raw));
        $this->assertStringNotContainsString(self::SECRET_PASSWORD, json_encode($secret->toArray()));
    }

    public function test_list_never_exposes_the_secret_password(): void
    {
        Http::fake(['router.test/rest/ppp/secret' => Http::response([
            ['.id' => '*1', 'name' => 'testuser', 'password' => self::SECRET_PASSWORD],
        ], 200)]);

        $result = $this->secrets()->list();

        $this->assertStringNotContainsString(self::SECRET_PASSWORD, json_encode($result->map->toArray()->all()));
        $this->assertStringNotContainsString(self::SECRET_PASSWORD, json_encode($result->map(fn ($s) => $s->raw)->all()));
    }

    public function test_add_sends_the_password_in_the_request_body(): void
    {
        // Sending a password TO RouterOS (creating/setting it) is normal
        // and expected -- the security guarantee is that this package
        // never echoes a password BACK out of a response, not that it
        // can't be used to set one.
        Http::fake(['router.test/rest/ppp/secret' => Http::response(
            ['.id' => '*A', 'name' => 'testuser'],
            200
        )]);

        $this->secrets()->add(['name' => 'testuser', 'password' => self::SECRET_PASSWORD, 'service' => 'pppoe']);

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['password'] === self::SECRET_PASSWORD);
    }

    public function test_add_without_name_throws_before_sending_a_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidResourceException::class);

        $this->secrets()->add(['service' => 'pppoe']);
    }

    public function test_update_sends_patch(): void
    {
        Http::fake(['router.test/rest/ppp/secret/*1' => Http::response(['.id' => '*1', 'comment' => 'updated'], 200)]);

        $secret = $this->secrets()->update('*1', ['comment' => 'updated']);

        $this->assertSame('updated', $secret->comment);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_remove_sends_delete(): void
    {
        Http::fake(['router.test/rest/ppp/secret/*1' => Http::response('', 200)]);

        $this->secrets()->remove('*1');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_enable_and_disable_patch_the_disabled_field(): void
    {
        Http::fake(['router.test/rest/ppp/secret/*1' => Http::response(['.id' => '*1', 'disabled' => 'true'], 200)]);

        $this->assertTrue($this->secrets()->disable('*1')->disabled);
        Http::assertSent(fn ($r) => $r['disabled'] === 'true');
    }

    public function test_update_never_retries(): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => 3, 'sleep' => 0]);

        $attempts = 0;
        Http::fake(['router.test/rest/ppp/secret/*1' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        try {
            $this->secrets()->update('*1', ['comment' => 'x']);
        } catch (RouterOsException) {
        }

        $this->assertSame(1, $attempts);
    }

    public function test_find_rejects_invalid_identifiers_without_sending_a_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidMenuPathException::class);

        $this->secrets()->find('..');
    }

    public function test_add_error_response_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/ppp/secret' => Http::response(['error' => 400, 'message' => 'Bad Request'], 400)]);

        $this->expectException(RouterOsException::class);

        $this->secrets()->add(['name' => 'bad']);
    }

    public function test_exceptions_never_contain_the_router_credential_password(): void
    {
        Http::fake(['router.test/rest/ppp/secret' => Http::response(['error' => 400, 'message' => 'Bad Request', 'detail' => 'x'], 400)]);

        try {
            $this->secrets()->add(['name' => 'bad']);
            $this->fail('Expected RouterOsException was not thrown.');
        } catch (RouterOsException $e) {
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, (string) $e->getRouterOsDetail());
        }
    }
}
