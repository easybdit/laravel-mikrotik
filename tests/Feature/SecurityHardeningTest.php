<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Connection\ConnectionManager;
use Easybdit\LaravelMikrotik\Exceptions\InvalidResourceException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Easybdit\LaravelMikrotik\Exceptions\UnsafeOperationException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Support\SecretRedactor;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Facades\Http;

// P25 follow-up. HTTP-fake / local tests only -- nothing here touched a real router.
class SecurityHardeningTest extends TestCase
{
    private function routes()
    {
        return Mikrotik::connection()->ip()->routes();
    }

    private function users()
    {
        return Mikrotik::connection()->users();
    }

    // ---- default route: update()

    public function test_default_route_update_that_disables_or_retargets_is_refused(): void
    {
        Http::fake(['router.test/rest/ip/route/*1' => Http::response(['.id' => '*1', 'dst-address' => '0.0.0.0/0'], 200)]);

        foreach ([['disabled' => true], ['disabled' => 'true'], ['dst-address' => '10.0.0.0/8']] as $attrs) {
            try {
                $this->routes()->update('*1', $attrs);
                $this->fail('expected UnsafeOperationException');
            } catch (UnsafeOperationException) {
                $this->assertTrue(true);
            }
        }
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_default_route_update_is_allowed_with_explicit_override(): void
    {
        Http::fake(['router.test/rest/ip/route/*1' => Http::response(['.id' => '*1', 'disabled' => 'true'], 200)]);

        $this->routes()->update('*1', ['disabled' => true], allowDefaultRoute: true);

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH');
    }

    public function test_ordinary_route_updates_cost_no_extra_read(): void
    {
        Http::fake(['router.test/rest/ip/route/*1' => Http::response(['.id' => '*1', 'comment' => 'x'], 200)]);

        $this->routes()->update('*1', ['comment' => 'x', 'gateway' => '10.0.0.1']);
        $this->routes()->update('*1', ['disabled' => false]);

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => $r->method() === 'GET');
    }

    public function test_disable_sends_exactly_one_guard_read_and_one_patch(): void
    {
        Http::fake(['router.test/rest/ip/route/*2' => Http::response(['.id' => '*2', 'dst-address' => '10.9.0.0/24'], 200)]);

        $this->routes()->disable('*2');

        Http::assertSentCount(2);
    }

    // ---- active user

    public function test_active_user_cannot_be_removed_disabled_or_renamed_by_default(): void
    {
        Http::fake(['router.test/rest/user/*1' => Http::response(['.id' => '*1', 'name' => 'admin'], 200)]);

        foreach ([
            fn () => $this->users()->remove('*1'),
            fn () => $this->users()->disable('*1'),
            fn () => $this->users()->update('*1', ['disabled' => 'yes']),
            fn () => $this->users()->update('*1', ['name' => 'renamed']),
        ] as $call) {
            try {
                $call();
                $this->fail('expected UnsafeOperationException');
            } catch (UnsafeOperationException $e) {
                $this->assertStringNotContainsString('super-secret-password', $e->getMessage());
            }
        }
        Http::assertNotSent(fn ($r) => in_array($r->method(), ['PATCH', 'DELETE'], true));
    }

    public function test_active_user_override_and_other_users_work(): void
    {
        Http::fake([
            'router.test/rest/user/*1' => Http::response(['.id' => '*1', 'name' => 'admin'], 200),
            'router.test/rest/user/*2' => Http::response(['.id' => '*2', 'name' => 'ops'], 200),
        ]);

        $this->users()->disable('*1', allowActiveUser: true);
        $this->users()->remove('*2');
        $this->users()->update('*1', ['group' => 'read']); // group change: unguarded, no read

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/user/*2'));
        Http::assertSentCount(4); // disable: 1 patch; remove: guard get + delete; group update: 1 patch
    }

    public function test_invalid_attributes_are_rejected_before_the_guard_read(): void
    {
        Http::fake();

        try {
            $this->users()->update('*1', ['name' => ['x']]);
            $this->fail('expected InvalidResourceException');
        } catch (InvalidResourceException) {
            Http::assertNothingSent();
        }
    }

    // ---- redaction

    public function test_redactor_handles_nested_and_case_insensitive_keys(): void
    {
        $out = SecretRedactor::redact([
            'name' => 'x',
            'Password' => 'p1',
            'nested' => ['secret' => 's', 'ok' => 1, 'deeper' => [['private-key' => 'k', 'keep' => 'y']]],
        ]);

        $this->assertSame(['name' => 'x', 'nested' => ['ok' => 1, 'deeper' => [['keep' => 'y']]]], $out);
    }

    public function test_every_typed_dto_raw_is_free_of_credential_fields(): void
    {
        Http::fake(['router.test/rest/ip/address' => Http::response([
            ['.id' => '*1', 'address' => '10.0.0.1/24', 'password' => 'leak', 'extra' => ['psk' => 'leak2']],
        ], 200)]);

        $raw = Mikrotik::connection()->ip()->addresses()->list()[0]->raw;

        $this->assertStringNotContainsString('leak', json_encode($raw));
    }

    public function test_menu_redacts_nested_credentials_and_with_secrets_keeps_them(): void
    {
        Http::fake(['router.test/rest/x/y' => Http::response([['name' => 'a', 'conf' => ['password' => 'leak']]], 200)]);
        $menu = Mikrotik::connection()->menu('x/y');

        $this->assertStringNotContainsString('leak', json_encode($menu->get()));
        $this->assertStringContainsString('leak', json_encode($menu->withSecrets()->get()));
    }

    public function test_router_error_text_echoing_the_connection_password_is_scrubbed(): void
    {
        Http::fake(['router.test/rest/system/resource' => Http::response(
            ['error' => 400, 'message' => 'bad super-secret-password here', 'detail' => 'x super-secret-password'],
            400
        )]);

        try {
            Mikrotik::connection()->resource();
            $this->fail('expected RouterOsException');
        } catch (RouterOsException $e) {
            $this->assertStringNotContainsString('super-secret-password', $e->getMessage());
            $this->assertStringNotContainsString('super-secret-password', (string) $e->getRouterOsDetail());
        }
    }

    // ---- validation / defaults

    public function test_ip_pool_writes_validate_attributes_and_control_characters(): void
    {
        $pools = Mikrotik::connection()->ip()->pools();

        foreach ([['name' => 'p', '.id' => '*1'], ['name' => 'p', 'ranges' => ['a']], ['name' => "p\0"]] as $bad) {
            try {
                $pools->add($bad);
                $this->fail('expected InvalidResourceException');
            } catch (InvalidResourceException) {
                $this->assertTrue(true);
            }
        }
        Http::assertNothingSent();
    }

    public function test_multiline_scheduler_script_text_is_still_allowed(): void
    {
        Http::fake(['router.test/rest/system/scheduler' => Http::response(['.id' => '*1', 'name' => 's'], 200)]);

        Mikrotik::connection()->scheduler()->add(['name' => 's', 'on-event' => "line1\nline2\tx", 'disabled' => true]);

        Http::assertSentCount(1);
    }

    public function test_tls_verification_is_enabled_unless_explicitly_disabled(): void
    {
        config()->set('mikrotik.connections.nov', ['host' => 'h.test', 'username' => 'u', 'password' => 'p']);

        $transport = $this->transportOf(app(ConnectionManager::class)->connection('nov'));

        $this->assertTrue((new \ReflectionProperty($transport, 'verifyTls'))->getValue($transport));
    }

    private function transportOf(object $connection): object
    {
        $p = new \ReflectionProperty($connection, 'transport');

        return $p->getValue($connection);
    }
}
