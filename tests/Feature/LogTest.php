<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\MalformedResponseException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class LogTest extends TestCase
{
    private const LOG_URL = 'router.test/rest/log';

    public function test_normal_log_response_is_normalized_correctly(): void
    {
        Http::fake([
            self::LOG_URL => Http::response([
                ['.id' => '*1', 'time' => 'jul/19 12:37:06', 'topics' => 'system,info', 'message' => 'router rebooted'],
            ], 200),
        ]);

        $logs = Mikrotik::connection()->logs();
        $entry = $logs->all()[0];

        $this->assertSame('*1', $entry->id);
        $this->assertSame('jul/19 12:37:06', $entry->time);
        $this->assertSame('system,info', $entry->topics);
        $this->assertSame(['system', 'info'], $entry->topicsList());
        $this->assertSame('router rebooted', $entry->message);
    }

    public function test_multiple_log_entries_are_all_present_in_order(): void
    {
        Http::fake([
            self::LOG_URL => Http::response([
                ['time' => '12:00:00', 'topics' => 'info', 'message' => 'first'],
                ['time' => '12:00:05', 'topics' => 'info', 'message' => 'second'],
            ], 200),
        ]);

        $logs = Mikrotik::connection()->logs();

        $this->assertSame(2, $logs->count());
        $this->assertSame('first', $logs->all()[0]->message);
        $this->assertSame('second', $logs->all()[1]->message);
    }

    public function test_missing_optional_fields_do_not_throw_and_are_null(): void
    {
        Http::fake([
            self::LOG_URL => Http::response([
                ['message' => 'no time or topics given'],
            ], 200),
        ]);

        $entry = Mikrotik::connection()->logs()->all()[0];

        $this->assertNull($entry->id);
        $this->assertNull($entry->time);
        $this->assertNull($entry->topics);
        $this->assertSame([], $entry->topicsList());
        $this->assertSame('no time or topics given', $entry->message);
    }

    public function test_unknown_field_is_preserved_in_raw_without_breaking_normalization(): void
    {
        Http::fake([
            self::LOG_URL => Http::response([
                ['message' => 'x', 'some-future-field' => 'y'],
            ], 200),
        ]);

        $entry = Mikrotik::connection()->logs()->all()[0];

        $this->assertSame('x', $entry->message);
        $this->assertArrayHasKey('some-future-field', $entry->raw);
    }

    public function test_malformed_json_body_throws_malformed_response_exception(): void
    {
        Http::fake([
            self::LOG_URL => Http::response('not json {{{', 200),
        ]);

        $this->expectException(MalformedResponseException::class);

        Mikrotik::connection()->logs();
    }

    public function test_empty_response_returns_empty_collection_not_an_error(): void
    {
        Http::fake([
            self::LOG_URL => Http::response([], 200),
        ]);

        $logs = Mikrotik::connection()->logs();

        $this->assertTrue($logs->isEmpty());
        $this->assertSame([], $logs->all());
    }

    public function test_error_response_throws_router_os_exception(): void
    {
        Http::fake([
            self::LOG_URL => Http::response(
                ['error' => 500, 'message' => 'Internal Server Error'],
                500
            ),
        ]);

        $this->expectException(RouterOsException::class);

        Mikrotik::connection()->logs();
    }

    public function test_filter_is_sent_as_a_query_parameter(): void
    {
        // Trailing wildcard: the actual request URL will carry a
        // "?topics=critical" query string, which a bare (non-wildcarded)
        // fake pattern does not match — see RestTransport's URL building.
        Http::fake([
            'router.test/rest/log*' => Http::response([], 200),
        ]);

        Mikrotik::connection()->logs(['topics' => 'critical']);

        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://router.test/rest/log?topics=critical';
        });
    }

    public function test_a_filter_not_matching_the_fake_url_fails_fast_instead_of_hitting_the_network(): void
    {
        // Confirms Http::preventStrayRequests() (TestCase::setUp()) also
        // guards query-string-filtered requests, not just plain ones.
        Http::fake([
            'router.test/rest/system/resource' => Http::response([], 200),
        ]);

        $this->expectException(\Illuminate\Http\Client\StrayRequestException::class);

        Mikrotik::connection()->logs(['topics' => 'critical']);
    }
}
