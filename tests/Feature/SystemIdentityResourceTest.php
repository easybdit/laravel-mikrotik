<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class SystemIdentityResourceTest extends TestCase
{
    private const PASSWORD = 'super-secret-password';

    private function identity()
    {
        return Mikrotik::connection()->systemIdentity();
    }

    public function test_get_returns_a_single_typed_dto_from_a_single_object_response(): void
    {
        Http::fake(['router.test/rest/system/identity' => Http::response(['name' => 'MyRouter'], 200)]);

        $identity = $this->identity()->get();

        $this->assertSame('MyRouter', $identity->name);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://router.test/rest/system/identity');
    }

    public function test_update_posts_to_the_set_path_then_re_fetches_via_get(): void
    {
        // Confirmed against a real device (P21): a naive PATCH with no
        // identifier was rejected by RouterOS ("missing or invalid
        // resource identifier"); POST .../set is the confirmed working
        // form, and it returns an empty body on success, not the
        // updated record -- update() posts the write, then re-fetches.
        Http::fake([
            'router.test/rest/system/identity/set' => Http::response([], 200),
            'router.test/rest/system/identity' => Http::response(['name' => 'NewName'], 200),
        ]);

        $identity = $this->identity()->update('NewName');

        $this->assertSame('NewName', $identity->name);
        Http::assertSent(function ($r) {
            return $r->method() === 'POST'
                && $r->url() === 'https://router.test/rest/system/identity/set'
                && $r['name'] === 'NewName';
        });
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://router.test/rest/system/identity');
    }

    public function test_update_error_response_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/system/identity/set' => Http::response(['error' => 400, 'message' => 'Bad Request'], 400)]);

        $this->expectException(RouterOsException::class);

        $this->identity()->update('');
    }

    public function test_update_never_retries_even_when_the_connection_has_retry_configured(): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => 3, 'sleep' => 0]);

        $attempts = 0;
        Http::fake(['router.test/rest/system/identity/set' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        try {
            $this->identity()->update('x');
        } catch (RouterOsException) {
        }

        $this->assertSame(1, $attempts);
    }

    public function test_get_results_never_contain_the_password(): void
    {
        Http::fake(['router.test/rest/system/identity' => Http::response(['name' => 'MyRouter'], 200)]);

        $identity = $this->identity()->get();

        $this->assertStringNotContainsString(self::PASSWORD, json_encode($identity->toArray()));
    }
}
