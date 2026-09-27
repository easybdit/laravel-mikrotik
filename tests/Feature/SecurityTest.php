<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\AuthenticationException;
use Easybdit\LaravelMikrotik\Exceptions\ConnectionException as MikrotikConnectionException;
use Easybdit\LaravelMikrotik\Exceptions\MikrotikException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Support\Facades\Http;

class SecurityTest extends TestCase
{
    private const PASSWORD = 'super-secret-password'; // matches TestCase::defineEnvironment()

    public function test_authentication_exception_message_never_contains_the_password(): void
    {
        Http::fake([
            'router.test/rest/system/resource' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        try {
            Mikrotik::connection()->resource();
            $this->fail('Expected AuthenticationException was not thrown.');
        } catch (AuthenticationException $e) {
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString('admin', $e->getMessage());
        }
    }

    public function test_connection_exception_message_never_contains_the_password(): void
    {
        Http::fake([
            'router.test/*' => function () {
                throw new HttpConnectionException('cURL error 7: Failed to connect');
            },
        ]);

        try {
            Mikrotik::connection()->resource();
            $this->fail('Expected connection exception was not thrown.');
        } catch (MikrotikConnectionException $e) {
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }

    public function test_connection_exception_chain_never_retains_the_raw_request_carrying_exception(): void
    {
        // The Guzzle exception this rethrows carries the full outgoing
        // PSR-7 request via getRequest() — including the Authorization:
        // Basic header. Regression guard: that object (or Laravel's own
        // wrapper around it) must never be reachable via getPrevious(),
        // however deep, since an application error tracker that
        // serializes full exception chains could otherwise surface it.
        Http::fake([
            'router.test/*' => function () {
                throw new HttpConnectionException(
                    'cURL error 7: Failed to connect to router.test port 443: Connection refused',
                    0,
                    new GuzzleConnectException(
                        'cURL error 7: Failed to connect to router.test port 443: Connection refused',
                        new \GuzzleHttp\Psr7\Request(
                            'GET',
                            'https://router.test/rest/system/resource',
                            ['Authorization' => 'Basic ' . base64_encode('admin:' . self::PASSWORD)]
                        )
                    )
                );
            },
        ]);

        try {
            Mikrotik::connection()->resource();
            $this->fail('Expected connection exception was not thrown.');
        } catch (MikrotikConnectionException $e) {
            $previous = $e->getPrevious();

            $this->assertNotInstanceOf(HttpConnectionException::class, $previous);
            $this->assertNotInstanceOf(GuzzleConnectException::class, $previous);

            // Walk whatever chain remains (if any) — none of it may expose
            // the password, and none of it may carry a PSR-7 request.
            while ($previous !== null) {
                $this->assertStringNotContainsString(self::PASSWORD, $previous->getMessage());
                $this->assertFalse(method_exists($previous, 'getRequest'));
                $previous = $previous->getPrevious();
            }
        }
    }

    public function test_router_os_exception_message_never_contains_the_password(): void
    {
        Http::fake([
            'router.test/rest/system/resource' => Http::response(
                ['error' => 500, 'message' => 'Internal Server Error', 'detail' => 'boom'],
                500
            ),
        ]);

        try {
            Mikrotik::connection()->resource();
            $this->fail('Expected RouterOsException was not thrown.');
        } catch (RouterOsException $e) {
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, (string) $e->getRouterOsDetail());
        }
    }

    public function test_no_mikrotik_exception_message_ever_contains_the_configured_password(): void
    {
        // Broad net: whichever exception type is thrown for any of these
        // failure modes, none of them may contain the password anywhere.
        $scenarios = [
            fn () => Http::fake(['router.test/rest/system/resource' => Http::response([], 401)]),
            fn () => Http::fake(['router.test/rest/system/resource' => Http::response('{bad json', 200)]),
            fn () => Http::fake(['router.test/rest/system/resource' => Http::response(['message' => 'err'], 503)]),
        ];

        foreach ($scenarios as $setup) {
            $setup();

            try {
                Mikrotik::connection()->resource();
            } catch (MikrotikException $e) {
                $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            }
        }
    }

    public function test_authorization_header_is_sent_but_never_echoed_in_any_exception(): void
    {
        Http::fake([
            'router.test/rest/system/resource' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        try {
            Mikrotik::connection()->resource();
        } catch (AuthenticationException $e) {
            $this->assertStringNotContainsString('Authorization', $e->getMessage());
            $this->assertStringNotContainsString('Basic ', $e->getMessage());
        }

        // Confirm the request really did carry Basic Auth (i.e. the
        // absence above is because we deliberately don't echo it, not
        // because auth was never attempted).
        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization')
                && str_starts_with($request->header('Authorization')[0], 'Basic ');
        });
    }
}
