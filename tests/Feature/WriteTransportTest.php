<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\AuthenticationException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Easybdit\LaravelMikrotik\Transport\RestTransport;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * P12: transport-layer put()/patch()/delete() foundation. Nothing in
 * this package calls these publicly yet (menu() stays read-only, no
 * typed write API exists before P13) — these tests exercise
 * RestTransport directly, the same way its existing get()/post() are
 * only reachable through RouterConnection/Menu in normal use.
 */
class WriteTransportTest extends TestCase
{
    private const PASSWORD = 'super-secret-password'; // matches TestCase::defineEnvironment()

    private function transport(int $retryTimes = 0, int $retrySleepMs = 0): RestTransport
    {
        return new RestTransport(
            host: 'router.test',
            port: 443,
            username: 'admin',
            password: self::PASSWORD,
            retryTimes: $retryTimes,
            retrySleepMilliseconds: $retrySleepMs,
        );
    }

    public function test_put_sends_the_correct_method_path_and_body(): void
    {
        Http::fake(['router.test/rest/ip/address' => Http::response(
            ['.id' => '*1', 'address' => '192.168.88.1/24'],
            200
        )]);

        $result = $this->transport()->put('/ip/address', ['address' => '192.168.88.1/24', 'interface' => 'bridge1']);

        $this->assertSame('192.168.88.1/24', $result['address']);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->url() === 'https://router.test/rest/ip/address'
                && $request['address'] === '192.168.88.1/24'
                && $request['interface'] === 'bridge1';
        });
    }

    public function test_patch_sends_the_correct_method_path_and_body(): void
    {
        Http::fake(['router.test/rest/ip/address/*1' => Http::response(
            ['.id' => '*1', 'address' => '10.0.0.1/24'],
            200
        )]);

        $result = $this->transport()->patch('/ip/address/*1', ['address' => '10.0.0.1/24']);

        $this->assertSame('10.0.0.1/24', $result['address']);

        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && $request->url() === 'https://router.test/rest/ip/address/*1'
                && $request['address'] === '10.0.0.1/24';
        });
    }

    public function test_delete_sends_the_correct_method_and_path_with_no_body(): void
    {
        Http::fake(['router.test/rest/ip/address/*1' => Http::response([], 200)]);

        $this->transport()->delete('/ip/address/*1');

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && $request->url() === 'https://router.test/rest/ip/address/*1'
                && $request->body() === '';
        });
    }

    public function test_post_write_sends_the_correct_method_path_and_body(): void
    {
        // P21: RouterOS's own "set" console command for a singleton
        // menu returns an empty body on success (confirmed against a
        // real device) -- this exercises the transport-level method/
        // path/body only; ResourceTest classes cover the
        // empty-response-then-follow-up-get() behavior this enables.
        Http::fake(['router.test/rest/system/identity/set' => Http::response([], 200)]);

        $result = $this->transport()->postWrite('/system/identity/set', ['name' => 'MyRouter']);

        $this->assertSame([], $result);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://router.test/rest/system/identity/set'
                && $request['name'] === 'MyRouter';
        });
    }

    public function test_post_write_never_retries_even_when_retry_is_configured(): void
    {
        $attempts = 0;
        Http::fake(['router.test/rest/system/identity/set' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        $transport = $this->transport(retryTimes: 3, retrySleepMs: 0);

        try {
            $transport->postWrite('/system/identity/set', ['name' => 'x']);
        } catch (RouterOsException) {
            // expected -- this test only cares about the attempt count.
        }

        $this->assertSame(1, $attempts, 'a write must never be retried, even with retry configured on the transport');
    }

    public function test_post_write_error_response_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/system/identity/set' => Http::response(
            ['error' => 400, 'message' => 'Bad Request', 'detail' => 'missing or invalid resource identifier'],
            400
        )]);

        $this->expectException(RouterOsException::class);

        $this->transport()->postWrite('/system/identity/set', ['name' => 'x']);
    }

    public function test_put_error_response_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/ip/address' => Http::response(
            ['error' => 400, 'message' => 'Bad Request', 'detail' => 'missing address'],
            400
        )]);

        $this->expectException(RouterOsException::class);

        $this->transport()->put('/ip/address', []);
    }

    public function test_patch_not_found_throws_router_os_exception(): void
    {
        // Matches the official documented shape for an unknown identifier
        // (help.mikrotik.com "REST API"): {"error":404,"message":"Not Found"}.
        Http::fake(['router.test/rest/ip/address/*99' => Http::response(
            ['error' => 404, 'message' => 'Not Found'],
            404
        )]);

        $this->expectException(RouterOsException::class);

        $this->transport()->patch('/ip/address/*99', ['address' => '1.2.3.4/32']);
    }

    public function test_delete_not_found_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/ip/address/*99' => Http::response(
            ['error' => 404, 'message' => 'Not Found'],
            404
        )]);

        $this->expectException(RouterOsException::class);

        $this->transport()->delete('/ip/address/*99');
    }

    public function test_write_methods_reject_401_as_authentication_exception(): void
    {
        Http::fake(['router.test/rest/ip/address' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->expectException(AuthenticationException::class);

        $this->transport()->put('/ip/address', ['address' => '1.2.3.4/32']);
    }

    public function test_write_methods_reject_5xx_as_router_os_exception(): void
    {
        Http::fake(['router.test/rest/ip/address/*1' => Http::response(
            ['message' => 'Internal Server Error'],
            500
        )]);

        $this->expectException(RouterOsException::class);

        $this->transport()->patch('/ip/address/*1', ['address' => '1.2.3.4/32']);
    }

    public function test_write_methods_never_retry_even_when_retry_is_configured(): void
    {
        $attempts = 0;
        Http::fake(['router.test/rest/ip/address' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        $transport = $this->transport(retryTimes: 3, retrySleepMs: 0);

        try {
            $transport->put('/ip/address', ['address' => '1.2.3.4/32']);
        } catch (RouterOsException) {
            // expected -- this test only cares about the attempt count.
        }

        $this->assertSame(1, $attempts, 'a write must never be retried, even with retry configured on the transport');
    }

    public function test_read_still_retries_on_the_same_transport_instance_that_a_write_did_not(): void
    {
        // Contrast case: the SAME RestTransport instance, same retry
        // config -- get() retries, put() (tested above) does not. Proves
        // this is a per-method distinction, not retry being globally off.
        $attempts = 0;
        Http::fake(['router.test/rest/system/resource' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        $transport = $this->transport(retryTimes: 3, retrySleepMs: 0);

        try {
            $transport->get('/system/resource');
        } catch (RouterOsException) {
            // expected.
        }

        $this->assertSame(3, $attempts);
    }

    public function test_write_methods_never_retry_a_connection_failure_either(): void
    {
        $attempts = 0;
        Http::fake(['router.test/rest/ip/address' => function () use (&$attempts) {
            $attempts++;

            throw new HttpConnectionException('cURL error 28: Operation timed out');
        }]);

        $transport = $this->transport(retryTimes: 3, retrySleepMs: 0);

        try {
            $transport->put('/ip/address', ['address' => '1.2.3.4/32']);
        } catch (\Easybdit\LaravelMikrotik\Exceptions\ConnectionException) {
            // expected.
        }

        $this->assertSame(1, $attempts);
    }

    public function test_write_exceptions_never_contain_the_password(): void
    {
        Http::fake(['router.test/rest/ip/address' => Http::response(
            ['error' => 400, 'message' => 'Bad Request', 'detail' => 'invalid address'],
            400
        )]);

        try {
            $this->transport()->put('/ip/address', ['address' => 'not-an-address']);
            $this->fail('Expected RouterOsException was not thrown.');
        } catch (RouterOsException $e) {
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, (string) $e->getRouterOsDetail());
        }
    }

    public function test_write_exceptions_never_echo_the_request_body(): void
    {
        // The exception must reflect RouterOS's *response*, never echo
        // back what this process sent -- relevant once a future phase
        // writes sensitive router-config values (e.g. a PPP secret's
        // password) in a request body.
        Http::fake(['router.test/rest/ppp/secret' => Http::response(
            ['error' => 400, 'message' => 'Bad Request', 'detail' => 'missing name'],
            400
        )]);

        try {
            $this->transport()->put('/ppp/secret', ['name' => 'alice', 'password' => 'sensitive-config-value-xyz']);
            $this->fail('Expected RouterOsException was not thrown.');
        } catch (RouterOsException $e) {
            $this->assertStringNotContainsString('sensitive-config-value-xyz', $e->getMessage());
            $this->assertStringNotContainsString('sensitive-config-value-xyz', (string) $e->getRouterOsDetail());
        }
    }

    public function test_write_requests_still_send_authorization_header(): void
    {
        Http::fake(['router.test/rest/ip/address' => Http::response(['.id' => '*1'], 200)]);

        $this->transport()->put('/ip/address', ['address' => '1.2.3.4/32']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization')
                && str_starts_with($request->header('Authorization')[0], 'Basic ');
        });
    }
}
