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

class FirewallFilterResourceTest extends TestCase
{
    private const PASSWORD = 'super-secret-password';

    private function filter()
    {
        return Mikrotik::connection()->firewall()->filter();
    }

    public function test_list_returns_a_collection_of_typed_dtos(): void
    {
        Http::fake(['router.test/rest/ip/firewall/filter' => Http::response([
            ['.id' => '*1', 'chain' => 'forward', 'action' => 'accept', 'disabled' => 'false'],
            ['.id' => '*2', 'chain' => 'input', 'action' => 'drop', 'disabled' => 'true'],
        ], 200)]);

        $result = $this->filter()->list();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
        $this->assertSame('forward', $result[0]->chain);
        $this->assertFalse($result[0]->disabled);
        $this->assertTrue($result[1]->disabled);
    }

    public function test_find_returns_a_single_typed_dto(): void
    {
        Http::fake(['router.test/rest/ip/firewall/filter/*1' => Http::response(
            ['.id' => '*1', 'chain' => 'forward', 'action' => 'drop', 'protocol' => 'tcp', 'comment' => 'test-rule'],
            200
        )]);

        $rule = $this->filter()->find('*1');

        $this->assertSame('*1', $rule->id);
        $this->assertSame('forward', $rule->chain);
        $this->assertSame('drop', $rule->action);
        $this->assertSame('test-rule', $rule->comment);
    }

    public function test_add_sends_put_and_returns_the_created_rule(): void
    {
        Http::fake(['router.test/rest/ip/firewall/filter' => Http::response(
            ['.id' => '*A', 'chain' => 'forward', 'action' => 'drop', 'disabled' => 'true'],
            200
        )]);

        $rule = $this->filter()->add(['chain' => 'forward', 'action' => 'drop', 'disabled' => true]);

        $this->assertSame('*A', $rule->id);
        Http::assertSent(function ($r) {
            return $r->method() === 'PUT'
                && $r->url() === 'https://router.test/rest/ip/firewall/filter'
                && $r['chain'] === 'forward'
                && $r['disabled'] === 'true';
        });
    }

    public function test_add_without_chain_throws_before_sending_a_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidResourceException::class);

        $this->filter()->add(['action' => 'drop']);
    }

    public function test_update_sends_patch_with_the_id_in_the_path(): void
    {
        Http::fake(['router.test/rest/ip/firewall/filter/*1' => Http::response(
            ['.id' => '*1', 'comment' => 'updated'],
            200
        )]);

        $rule = $this->filter()->update('*1', ['comment' => 'updated']);

        $this->assertSame('updated', $rule->comment);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r->url() === 'https://router.test/rest/ip/firewall/filter/*1');
    }

    public function test_remove_sends_delete_with_no_body(): void
    {
        Http::fake(['router.test/rest/ip/firewall/filter/*1' => Http::response('', 200)]);

        $this->filter()->remove('*1');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->body() === '');
    }

    public function test_enable_patches_disabled_false(): void
    {
        Http::fake(['router.test/rest/ip/firewall/filter/*1' => Http::response(['.id' => '*1', 'disabled' => 'false'], 200)]);

        $rule = $this->filter()->enable('*1');

        $this->assertFalse($rule->disabled);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['disabled'] === 'false');
    }

    public function test_disable_patches_disabled_true(): void
    {
        Http::fake(['router.test/rest/ip/firewall/filter/*1' => Http::response(['.id' => '*1', 'disabled' => 'true'], 200)]);

        $rule = $this->filter()->disable('*1');

        $this->assertTrue($rule->disabled);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['disabled'] === 'true');
    }

    public function test_add_error_response_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/ip/firewall/filter' => Http::response(
            ['error' => 400, 'message' => 'Bad Request'],
            400
        )]);

        $this->expectException(RouterOsException::class);

        $this->filter()->add(['chain' => 'bad-chain']);
    }

    public function test_update_never_retries_even_when_the_connection_has_retry_configured(): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => 3, 'sleep' => 0]);

        $attempts = 0;
        Http::fake(['router.test/rest/ip/firewall/filter/*1' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        try {
            $this->filter()->update('*1', ['comment' => 'x']);
        } catch (RouterOsException) {
        }

        $this->assertSame(1, $attempts);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidIdentifierProvider(): array
    {
        return ['empty' => [''], 'traversal' => ['..'], 'slash' => ['*1/etc'], 'protocol-like' => ['http://evil.test']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIdentifierProvider')]
    public function test_find_rejects_invalid_identifiers_without_sending_a_request(string $id): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidMenuPathException::class);

        $this->filter()->find($id);
    }

    public function test_exceptions_never_contain_the_password(): void
    {
        Http::fake(['router.test/rest/ip/firewall/filter' => Http::response(
            ['error' => 400, 'message' => 'Bad Request', 'detail' => 'invalid chain'],
            400
        )]);

        try {
            $this->filter()->add(['chain' => 'bad']);
            $this->fail('Expected RouterOsException was not thrown.');
        } catch (RouterOsException $e) {
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, (string) $e->getRouterOsDetail());
        }
    }

    public function test_list_results_never_contain_the_password(): void
    {
        Http::fake(['router.test/rest/ip/firewall/filter' => Http::response([
            ['.id' => '*1', 'chain' => 'forward', 'comment' => 'not the password'],
        ], 200)]);

        $result = $this->filter()->list();

        $this->assertStringNotContainsString(self::PASSWORD, json_encode($result->map->toArray()->all()));
    }
}
