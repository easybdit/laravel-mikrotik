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

class QueueSimpleResourceTest extends TestCase
{
    private const PASSWORD = 'super-secret-password';

    private function simple()
    {
        return Mikrotik::connection()->queue()->simple();
    }

    public function test_list_returns_a_collection_of_typed_dtos(): void
    {
        Http::fake(['router.test/rest/queue/simple' => Http::response([
            ['.id' => '*1', 'name' => 'Master', 'target' => '192.168.1.0/24', 'max-limit' => '10M/10M', 'disabled' => 'false'],
        ], 200)]);

        $result = $this->simple()->list();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertSame('Master', $result[0]->name);
        $this->assertSame('10M/10M', $result[0]->maxLimit);
    }

    public function test_find_returns_a_single_typed_dto(): void
    {
        Http::fake(['router.test/rest/queue/simple/*1' => Http::response(
            ['.id' => '*1', 'name' => 'Master', 'target' => '192.168.1.0/24'],
            200
        )]);

        $queue = $this->simple()->find('*1');

        $this->assertSame('Master', $queue->name);
    }

    public function test_add_sends_put_and_returns_the_created_queue(): void
    {
        Http::fake(['router.test/rest/queue/simple' => Http::response(
            ['.id' => '*A', 'name' => 'test-queue', 'target' => '203.0.113.99/32'],
            200
        )]);

        $queue = $this->simple()->add(['name' => 'test-queue', 'target' => '203.0.113.99/32', 'max-limit' => '1M/1M']);

        $this->assertSame('*A', $queue->id);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['name'] === 'test-queue');
    }

    public function test_add_without_name_throws_before_sending_a_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidResourceException::class);

        $this->simple()->add(['target' => '192.168.1.0/24']);
    }

    public function test_update_sends_patch(): void
    {
        Http::fake(['router.test/rest/queue/simple/*1' => Http::response(['.id' => '*1', 'comment' => 'updated'], 200)]);

        $queue = $this->simple()->update('*1', ['comment' => 'updated']);

        $this->assertSame('updated', $queue->comment);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_remove_sends_delete(): void
    {
        Http::fake(['router.test/rest/queue/simple/*1' => Http::response('', 200)]);

        $this->simple()->remove('*1');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_enable_and_disable_patch_the_disabled_field(): void
    {
        Http::fake(['router.test/rest/queue/simple/*1' => Http::response(['.id' => '*1', 'disabled' => 'true'], 200)]);

        $this->assertTrue($this->simple()->disable('*1')->disabled);
        Http::assertSent(fn ($r) => $r['disabled'] === 'true');
    }

    public function test_update_never_retries(): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => 3, 'sleep' => 0]);

        $attempts = 0;
        Http::fake(['router.test/rest/queue/simple/*1' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        try {
            $this->simple()->update('*1', ['comment' => 'x']);
        } catch (RouterOsException) {
        }

        $this->assertSame(1, $attempts);
    }

    public function test_find_rejects_invalid_identifiers_without_sending_a_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidMenuPathException::class);

        $this->simple()->find('..');
    }

    public function test_add_error_response_throws_router_os_exception(): void
    {
        Http::fake(['router.test/rest/queue/simple' => Http::response(['error' => 400, 'message' => 'Bad Request'], 400)]);

        $this->expectException(RouterOsException::class);

        $this->simple()->add(['name' => 'bad']);
    }

    public function test_list_results_never_contain_the_password(): void
    {
        Http::fake(['router.test/rest/queue/simple' => Http::response([
            ['.id' => '*1', 'name' => 'Master', 'comment' => 'not the password'],
        ], 200)]);

        $result = $this->simple()->list();

        $this->assertStringNotContainsString(self::PASSWORD, json_encode($result->map->toArray()->all()));
    }
}
