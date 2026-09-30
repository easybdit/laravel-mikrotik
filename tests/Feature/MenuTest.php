<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\InvalidMenuPathException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class MenuTest extends TestCase
{
    private const PASSWORD = 'super-secret-password'; // matches TestCase::defineEnvironment()

    public function test_generic_menu_results_never_contain_the_configured_password(): void
    {
        Http::fake([
            'router.test/rest/ip/address' => Http::response([
                ['.id' => '*1', 'address' => '192.168.88.1/24', 'comment' => 'does not mention it either'],
            ], 200),
        ]);

        $rows = Mikrotik::connection()->menu('ip/address')->get();

        $this->assertStringNotContainsString(self::PASSWORD, json_encode($rows->all()));

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization')
                && str_starts_with($request->header('Authorization')[0], 'Basic ');
        });
    }

    public function test_get_returns_a_collection_of_raw_rows(): void
    {
        Http::fake([
            'router.test/rest/ip/address' => Http::response([
                ['.id' => '*1', 'address' => '192.168.88.1/24', 'interface' => 'bridge1', 'disabled' => 'false'],
                ['.id' => '*2', 'address' => '10.0.0.1/24', 'interface' => 'ether2', 'disabled' => 'false'],
            ], 200),
        ]);

        $rows = Mikrotik::connection()->menu('ip/address')->get();

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertCount(2, $rows);
        // Raw, untyped -- exactly the JSON-string values RouterOS REST sent,
        // no normalization/casting attempted for a menu this class knows
        // nothing about.
        $this->assertSame('192.168.88.1/24', $rows[0]['address']);
        $this->assertIsString($rows[0]['disabled']);
        $this->assertSame('false', $rows[0]['disabled']);
    }

    public function test_get_returns_an_empty_collection_for_an_empty_menu(): void
    {
        Http::fake(['router.test/rest/ip/address' => Http::response([], 200)]);

        $rows = Mikrotik::connection()->menu('ip/address')->get();

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertTrue($rows->isEmpty());
    }

    public function test_get_sends_simple_equality_filters_as_query_string(): void
    {
        Http::fake(['router.test/rest/ip/address*' => Http::response([
            ['.id' => '*2', 'address' => '10.0.0.1/24', 'interface' => 'ether2'],
        ], 200)]);

        $rows = Mikrotik::connection()->menu('ip/address')->get(['interface' => 'ether2']);

        $this->assertCount(1, $rows);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://router.test/rest/ip/address?interface=ether2';
        });
    }

    public function test_a_single_bare_object_response_is_wrapped_as_one_row(): void
    {
        // RouterOS REST's documented single-record shape (matches how
        // ResponseNormalizer::firstRecord() already treats other endpoints).
        Http::fake(['router.test/rest/ip/address' => Http::response(
            ['.id' => '*1', 'address' => '192.168.88.1/24'],
            200
        )]);

        $rows = Mikrotik::connection()->menu('ip/address')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('192.168.88.1/24', $rows[0]['address']);
    }

    public function test_find_requests_the_path_with_the_id_appended(): void
    {
        Http::fake(['router.test/rest/ip/address/*1' => Http::response(
            ['.id' => '*1', 'address' => '192.168.88.1/24'],
            200
        )]);

        $row = Mikrotik::connection()->menu('ip/address')->find('*1');

        $this->assertSame('192.168.88.1/24', $row['address']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://router.test/rest/ip/address/*1';
        });
    }

    public function test_find_accepts_a_named_identifier(): void
    {
        Http::fake(['router.test/rest/interface/ether1' => Http::response(
            ['.id' => '*1', 'name' => 'ether1', 'type' => 'ether'],
            200
        )]);

        $row = Mikrotik::connection()->menu('interface')->find('ether1');

        $this->assertSame('ether1', $row['name']);
    }

    public function test_find_does_not_swallow_a_routeros_error_into_null(): void
    {
        // Not-found behavior is deliberately NOT special-cased (see Menu's
        // docblock) -- any RouterOS-side failure, including a 404, must
        // still surface as a RouterOsException, not a null return.
        Http::fake(['router.test/rest/ip/address/*99' => Http::response(
            ['error' => 404, 'message' => 'Not Found'],
            404
        )]);

        $this->expectException(RouterOsException::class);

        Mikrotik::connection()->menu('ip/address')->find('*99');
    }

    public function test_malformed_response_throws_the_existing_exception_type(): void
    {
        Http::fake(['router.test/rest/ip/address' => Http::response('not json {{{', 200)]);

        $this->expectException(\Easybdit\LaravelMikrotik\Exceptions\MalformedResponseException::class);

        Mikrotik::connection()->menu('ip/address')->get();
    }

    /** @return array<string, array{0: string}> */
    public static function invalidPathProvider(): array
    {
        return [
            'empty' => [''],
            'leading slash' => ['/ip/address'],
            'trailing slash' => ['ip/address/'],
            'double slash' => ['ip//address'],
            'traversal' => ['ip/../address'],
            'absolute url' => ['https://evil.test/ip/address'],
            'protocol-like' => ['http://router.test'],
            'query string injection' => ['ip/address?evil=1'],
            'fragment' => ['ip/address#frag'],
            'whitespace' => ['ip address'],
            'at sign (userinfo injection)' => ['ip@address'],
            'backslash' => ['ip\\address'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPathProvider')]
    public function test_invalid_menu_paths_are_rejected(string $path): void
    {
        $this->expectException(InvalidMenuPathException::class);

        Mikrotik::connection()->menu($path);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidIdentifierProvider(): array
    {
        return [
            'empty' => [''],
            'slash' => ['*1/../../etc'],
            'traversal' => ['..'],
            'query string' => ['*1?evil=1'],
            'protocol-like' => ['http://evil.test'],
            'whitespace' => ['id with space'],
            'colon' => ['ether1:evil'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIdentifierProvider')]
    public function test_invalid_identifiers_are_rejected(string $id): void
    {
        $this->expectException(InvalidMenuPathException::class);

        Mikrotik::connection()->menu('ip/address')->find($id);
    }

    public function test_valid_menu_path_with_multiple_segments_is_accepted(): void
    {
        Http::fake(['router.test/rest/ip/firewall/filter' => Http::response([], 200)]);

        $rows = Mikrotik::connection()->menu('ip/firewall/filter')->get();

        $this->assertTrue($rows->isEmpty());
    }

    public function test_query_sends_post_to_the_print_path_with_the_query_stack(): void
    {
        Http::fake(['router.test/rest/interface/print' => Http::response([
            ['name' => 'ether1', 'type' => 'ether'],
        ], 200)]);

        $rows = Mikrotik::connection()->menu('interface')->query(['type=ether', 'type=bridge', '#|']);

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertCount(1, $rows);

        Http::assertSent(function ($r) {
            return $r->method() === 'POST'
                && $r->url() === 'https://router.test/rest/interface/print'
                && $r['.query'] === ['type=ether', 'type=bridge', '#|'];
        });
    }

    public function test_query_sends_proplist_alongside_the_query_stack(): void
    {
        Http::fake(['router.test/rest/ip/address/print' => Http::response([
            ['.id' => '*1', 'address' => '192.168.88.1/24'],
        ], 200)]);

        Mikrotik::connection()->menu('ip/address')->query(['dynamic=true'], ['.id', 'address']);

        Http::assertSent(function ($r) {
            return $r['.query'] === ['dynamic=true']
                && $r['.proplist'] === ['.id', 'address'];
        });
    }

    public function test_query_with_only_a_proplist_omits_the_query_key(): void
    {
        Http::fake(['router.test/rest/ip/address/print' => Http::response([], 200)]);

        Mikrotik::connection()->menu('ip/address')->query([], ['name']);

        Http::assertSent(function ($r) {
            return !array_key_exists('.query', $r->data())
                && $r['.proplist'] === ['name'];
        });
    }

    public function test_query_with_no_arguments_sends_an_empty_body(): void
    {
        Http::fake(['router.test/rest/interface/print' => Http::response([], 200)]);

        Mikrotik::connection()->menu('interface')->query();

        Http::assertSent(function ($r) {
            return $r->method() === 'POST'
                && $r->url() === 'https://router.test/rest/interface/print'
                && $r->data() === [];
        });
    }

    public function test_query_error_response_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/interface/print' => Http::response(
            ['error' => 400, 'message' => 'Bad Request', 'detail' => 'bad query word'],
            400
        )]);

        $this->expectException(RouterOsException::class);

        Mikrotik::connection()->menu('interface')->query(['not-a-valid-word']);
    }

    public function test_query_uses_the_same_read_retry_configuration_as_get(): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => 3, 'sleep' => 0]);

        $attempts = 0;
        Http::fake(['router.test/rest/interface/print' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        try {
            Mikrotik::connection()->menu('interface')->query(['type=ether']);
        } catch (RouterOsException) {
        }

        $this->assertSame(3, $attempts, 'query() is a read (POST-print), so it should retry like get()/post() already do');
    }

    public function test_query_results_never_contain_the_configured_password(): void
    {
        Http::fake(['router.test/rest/interface/print' => Http::response([
            ['name' => 'ether1', 'comment' => 'not the password'],
        ], 200)]);

        $rows = Mikrotik::connection()->menu('interface')->query(['type=ether']);

        $this->assertStringNotContainsString(self::PASSWORD, json_encode($rows->all()));
    }
}
