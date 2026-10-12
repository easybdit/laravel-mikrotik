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

// HTTP-fake tests only: P22 resources were NOT exercised against a real device.
class SchedulerResourceTest extends TestCase
{
    private const URL = 'router.test/rest/system/scheduler';

    private function res()
    {
        return Mikrotik::connection()->scheduler();
    }

    public function test_list_and_find_return_typed_dtos(): void
    {
        Http::fake([self::URL => Http::response([['.id' => '*1', 'name' => 'a', 'disabled' => 'false']], 200),
            self::URL . '/*1' => Http::response(['.id' => '*1', 'name' => 'a'], 200)]);

        $list = $this->res()->list();

        $this->assertInstanceOf(Collection::class, $list);
        $this->assertSame('a', $list[0]->name);
        $this->assertFalse($list[0]->disabled);
        $this->assertSame('*1', $this->res()->find('*1')->id);
    }

    public function test_invalid_identifier_is_rejected_before_any_request(): void
    {
        $this->expectException(InvalidMenuPathException::class);

        $this->res()->find('../x');
    }

    public function test_invalid_attributes_are_rejected_before_any_request(): void
    {
        foreach ([['.id' => '*1', 'name' => 'a'], ['name' => ['x']], ['na me' => 'a']] as $bad) {
            try {
                $this->res()->update('*1', $bad);
                $this->fail('expected InvalidResourceException');
            } catch (InvalidResourceException) {
                $this->assertTrue(true);
            }
        }
        Http::assertNothingSent();
    }

    public function test_remove_sends_delete_and_never_retries(): void
    {
        config()->set('mikrotik.connections.default.retry', ['times' => 3, 'sleep' => 0]);
        $attempts = 0;
        Http::fake([self::URL . '/*1' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal Server Error'], 500);
        }]);

        try {
            $this->res()->remove('*1');
            $this->fail('expected RouterOsException');
        } catch (RouterOsException) {
            $this->assertSame(1, $attempts);
        }
    }

    public function test_enable_and_disable_patch_the_disabled_field(): void
    {
        Http::fake([self::URL . '/*1' => Http::response(['.id' => '*1', 'disabled' => 'true'], 200)]);

        $this->assertTrue($this->res()->disable('*1')->disabled);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['disabled'] === 'true');
    }

    public function test_router_error_maps_to_router_os_exception_without_leaking_request_body(): void
    {
        Http::fake([self::URL => Http::response(['error' => 400, 'message' => 'failure: already have such entry'], 400)]);

        try {
            $this->res()->add(['name' => 'dup', 'password' => 'pw-should-not-leak']);
            $this->fail('expected RouterOsException');
        } catch (RouterOsException $e) {
            $this->assertStringNotContainsString('pw-should-not-leak', $e->getMessage());
            $this->assertStringNotContainsString('super-secret-password', $e->getMessage());
        }
    }

    public function test_add_requires_a_name(): void
    {
        $this->expectException(InvalidResourceException::class);

        $this->res()->add(['comment' => 'x']);
    }

    public function test_dto_maps_scheduler_fields_and_nothing_is_executed(): void
    {
        Http::fake([self::URL . '/*1' => Http::response([
            '.id' => '*1', 'name' => 'nightly', 'start-time' => '03:00:00', 'interval' => '1d',
            'on-event' => '/system script run x', 'run-count' => '4', 'disabled' => 'true',
        ], 200)]);

        $e = $this->res()->find('*1');

        $this->assertSame('1d', $e->interval);
        $this->assertSame('/system script run x', $e->onEvent);
        $this->assertSame(4, $e->runCount);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET');
    }
}
