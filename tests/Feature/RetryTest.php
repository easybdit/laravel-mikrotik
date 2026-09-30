<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\AuthenticationException;
use Easybdit\LaravelMikrotik\Exceptions\ConnectionException as MikrotikConnectionException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Support\Facades\Http;

class RetryTest extends TestCase
{
    private const RESOURCE_URL = 'router.test/rest/system/resource';
    private const PASSWORD = 'super-secret-password'; // matches TestCase::defineEnvironment()

    private function withRetry(int $times, int $sleep = 0): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => $times, 'sleep' => $sleep]);
    }

    public function test_retry_disabled_by_default_makes_exactly_one_request(): void
    {
        Http::fake([self::RESOURCE_URL => Http::response(['message' => 'Internal Server Error'], 500)]);

        try {
            Mikrotik::connection()->resource();
        } catch (RouterOsException) {
            // expected -- this test only cares about the request count.
        }

        Http::assertSentCount(1);
    }

    public function test_retry_disabled_by_default_does_not_retry_a_connection_failure(): void
    {
        // Http::fake()'s own recording only captures requests that
        // returned a Response -- a callback that throws (simulating a
        // transport-level connection failure) is never recorded, so
        // attempts are counted manually here instead of via
        // Http::assertSentCount().
        $attempts = 0;
        Http::fake([self::RESOURCE_URL => function () use (&$attempts) {
            $attempts++;

            throw new HttpConnectionException('cURL error 7: Failed to connect');
        }]);

        try {
            Mikrotik::connection()->resource();
        } catch (MikrotikConnectionException) {
            // expected.
        }

        $this->assertSame(1, $attempts);
    }

    public function test_retry_enabled_retries_a_5xx_response_and_succeeds_on_a_later_attempt(): void
    {
        $this->withRetry(times: 3, sleep: 0);

        $attempt = 0;
        Http::fake([self::RESOURCE_URL => function () use (&$attempt) {
            $attempt++;

            return $attempt < 3
                ? Http::response(['message' => 'Internal Server Error'], 500)
                : Http::response([['version' => '7.15 (stable)']], 200);
        }]);

        $resource = Mikrotik::connection()->resource();

        $this->assertSame('7.15 (stable)', $resource->version);
        Http::assertSentCount(3);
    }

    public function test_retry_stops_after_the_configured_attempts_and_still_throws_the_usual_exception(): void
    {
        $this->withRetry(times: 3, sleep: 0);

        Http::fake([self::RESOURCE_URL => Http::response(['message' => 'Internal Server Error'], 500)]);

        $this->expectException(RouterOsException::class);

        try {
            Mikrotik::connection()->resource();
        } finally {
            Http::assertSentCount(3);
        }
    }

    public function test_retry_never_retries_an_authentication_failure(): void
    {
        $this->withRetry(times: 3, sleep: 0);

        Http::fake([self::RESOURCE_URL => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->expectException(AuthenticationException::class);

        try {
            Mikrotik::connection()->resource();
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_retried_requests_never_leak_the_password_into_the_final_exception(): void
    {
        $this->withRetry(times: 3, sleep: 0);

        Http::fake([self::RESOURCE_URL => Http::response(['message' => 'Internal Server Error'], 500)]);

        try {
            Mikrotik::connection()->resource();
            $this->fail('Expected RouterOsException was not thrown.');
        } catch (RouterOsException $e) {
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, (string) $e->getRouterOsDetail());

            $previous = $e->getPrevious();
            while ($previous !== null) {
                $this->assertStringNotContainsString(self::PASSWORD, $previous->getMessage());
                $previous = $previous->getPrevious();
            }
        }

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization')
                && str_starts_with($request->header('Authorization')[0], 'Basic ');
        });
    }

    public function test_retry_never_retries_an_ordinary_routeros_4xx_error(): void
    {
        $this->withRetry(times: 3, sleep: 0);

        Http::fake([self::RESOURCE_URL => Http::response(['error' => 400, 'message' => 'Bad Request'], 400)]);

        $this->expectException(RouterOsException::class);

        try {
            Mikrotik::connection()->resource();
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_retry_retries_a_transient_connection_failure_then_succeeds(): void
    {
        $this->withRetry(times: 3, sleep: 0);

        $attempt = 0;
        Http::fake([self::RESOURCE_URL => function () use (&$attempt) {
            $attempt++;

            if ($attempt < 2) {
                throw new HttpConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response([['version' => '7.15 (stable)']], 200);
        }]);

        $resource = Mikrotik::connection()->resource();

        $this->assertSame('7.15 (stable)', $resource->version);
        $this->assertSame(2, $attempt);
    }

    public function test_retry_is_configured_per_connection_not_globally(): void
    {
        $this->withRetry(times: 3, sleep: 0); // 'default' only.

        config()->set('mikrotik.connections.branch-01', [
            'transport'  => 'rest',
            'host'       => 'branch.test',
            'port'       => 443,
            'username'   => 'admin',
            'password'   => 'branch-secret',
            'verify_tls' => true,
            'timeout'    => 5,
            // no 'retry' key at all for this connection.
        ]);

        Http::fake([
            self::RESOURCE_URL => Http::response(['message' => 'Internal Server Error'], 500),
            'branch.test/rest/system/resource' => Http::response(['message' => 'Internal Server Error'], 500),
        ]);

        try {
            Mikrotik::connection('default')->resource();
        } catch (RouterOsException) {
            // expected.
        }

        try {
            Mikrotik::connection('branch-01')->resource();
        } catch (RouterOsException) {
            // expected.
        }

        // 'default' has retry configured (3 attempts); 'branch-01' has no
        // 'retry' key at all and made exactly 1 -- proves the setting is
        // per connection, not a shared/global toggle.
        $this->assertSame(
            4,
            count(Http::recorded()),
            'expected 3 attempts for default + 1 for branch-01'
        );
    }
}
