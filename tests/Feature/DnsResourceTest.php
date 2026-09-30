<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class DnsResourceTest extends TestCase
{
    private const PASSWORD = 'super-secret-password';

    private function dns()
    {
        return Mikrotik::connection()->dns();
    }

    public function test_get_returns_a_single_typed_dto_from_a_single_object_response(): void
    {
        Http::fake(['router.test/rest/ip/dns' => Http::response(
            ['servers' => '8.8.8.8,1.1.1.1', 'allow-remote-requests' => 'false', 'cache-size' => '2048'],
            200
        )]);

        $settings = $this->dns()->get();

        $this->assertSame('8.8.8.8,1.1.1.1', $settings->servers);
        $this->assertFalse($settings->allowRemoteRequests);
        $this->assertSame('2048', $settings->cacheSize);

        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://router.test/rest/ip/dns');
    }

    public function test_update_posts_to_the_set_path_then_re_fetches_via_get(): void
    {
        // Confirmed against a real device (P21): RouterOS's own "set"
        // command returns an empty body on success, not the updated
        // record -- update() posts the write, then re-fetches via get().
        Http::fake([
            'router.test/rest/ip/dns/set' => Http::response([], 200),
            'router.test/rest/ip/dns' => Http::response(['servers' => '9.9.9.9'], 200),
        ]);

        $settings = $this->dns()->update(['servers' => '9.9.9.9']);

        $this->assertSame('9.9.9.9', $settings->servers);
        Http::assertSent(function ($r) {
            return $r->method() === 'POST'
                && $r->url() === 'https://router.test/rest/ip/dns/set'
                && $r['servers'] === '9.9.9.9';
        });
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://router.test/rest/ip/dns');
    }

    public function test_update_error_response_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/ip/dns/set' => Http::response(['error' => 400, 'message' => 'Bad Request'], 400)]);

        $this->expectException(RouterOsException::class);

        $this->dns()->update(['servers' => 'not-an-ip']);
    }

    public function test_update_never_retries_even_when_the_connection_has_retry_configured(): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => 3, 'sleep' => 0]);

        $attempts = 0;
        Http::fake(['router.test/rest/ip/dns/set' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        try {
            $this->dns()->update(['servers' => '9.9.9.9']);
        } catch (RouterOsException) {
        }

        $this->assertSame(1, $attempts);
    }

    public function test_get_results_never_contain_the_password(): void
    {
        Http::fake(['router.test/rest/ip/dns' => Http::response(['servers' => '8.8.8.8'], 200)]);

        $settings = $this->dns()->get();

        $this->assertStringNotContainsString(self::PASSWORD, json_encode($settings->toArray()));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($settings->raw));
    }
}
