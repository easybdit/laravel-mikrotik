<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\InvalidResourceException;
use Easybdit\LaravelMikrotik\Exceptions\UnsafeOperationException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Facades\Http;

// P25. HTTP-fake tests only.
class WriteSafeguardTest extends TestCase
{
    private function routes()
    {
        return Mikrotik::connection()->ip()->routes();
    }

    public function test_default_route_cannot_be_removed_or_disabled_by_default(): void
    {
        Http::fake(['router.test/rest/ip/route/*1' => Http::response(['.id' => '*1', 'dst-address' => '0.0.0.0/0'], 200)]);

        foreach ([fn () => $this->routes()->remove('*1'), fn () => $this->routes()->disable('*1')] as $call) {
            try {
                $call();
                $this->fail('expected UnsafeOperationException');
            } catch (UnsafeOperationException) {
                $this->assertTrue(true);
            }
        }
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_default_route_can_be_removed_when_explicitly_allowed(): void
    {
        Http::fake(['router.test/rest/ip/route/*1' => Http::response('', 200)]);

        $this->routes()->remove('*1', allowDefaultRoute: true);

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_non_default_route_is_checked_then_deleted(): void
    {
        Http::fake(['router.test/rest/ip/route/*2' => Http::response(['.id' => '*2', 'dst-address' => '203.0.113.0/24'], 200)]);

        $this->routes()->remove('*2');

        Http::assertSent(fn ($r) => $r->method() === 'GET');
        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_every_write_resource_rejects_control_keys_and_non_scalar_values(): void
    {
        $c = Mikrotik::connection();
        $calls = [
            fn ($a) => $c->ip()->addresses()->add($a + ['address' => '10.0.0.1/24']),
            fn ($a) => $c->ip()->routes()->add($a + ['dst-address' => '10.1.0.0/24']),
            fn ($a) => $c->firewall()->filter()->add($a),
            fn ($a) => $c->dhcp()->servers()->add($a),
            fn ($a) => $c->dhcp()->leases()->add($a),
            fn ($a) => $c->ppp()->secrets()->add($a + ['name' => 'n']),
            fn ($a) => $c->queue()->simple()->add($a + ['name' => 'q']),
            fn ($a) => $c->interface()->update('*1', $a),
            fn ($a) => $c->dns()->update($a),
        ];

        foreach ($calls as $call) {
            foreach ([['.query' => 'x'], ['comment' => ['nested']], ['comment' => null]] as $bad) {
                try {
                    $call($bad);
                    $this->fail('expected InvalidResourceException');
                } catch (InvalidResourceException) {
                    $this->assertTrue(true);
                }
            }
        }
        Http::assertNothingSent();
    }
}
