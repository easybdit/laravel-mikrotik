<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\InvalidQueryException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

// P23/P25. HTTP-fake tests only -- no real-device verification is claimed here.
class MenuQueryValidationTest extends TestCase
{
    #[DataProvider('badWords')]
    public function test_unsupported_words_are_rejected_before_any_request(string $word): void
    {
        try {
            Mikrotik::connection()->menu('interface')->query([$word]);
            $this->fail('expected InvalidQueryException');
        } catch (InvalidQueryException) {
            Http::assertNothingSent();
            $this->assertTrue(true);
        }
    }

    public static function badWords(): array
    {
        return [
            'greater' => ['>mtu=1500'], 'less' => ['<mtu=1500'], 'and' => ['#&'], 'not' => ['#!'],
            'contains' => ['name~eth'], 'bare' => ['name'], 'newline' => ["a=b\nc=d"], 'empty' => [''],
            'url' => ['http://x'], 'leading dash' => ['-name=x'],
        ];
    }

    public function test_documented_forms_are_accepted(): void
    {
        Http::fake(['router.test/rest/interface/print' => Http::response([['name' => 'ether1']], 200)]);

        Mikrotik::connection()->menu('interface')->query(['type=ether', 'type=vlan', '#|!']);
        Mikrotik::connection()->menu('interface')->query(['comment=a b=c', 'x=', '#|']);

        Http::assertSentCount(2);
    }

    public function test_invalid_proplist_field_is_rejected(): void
    {
        $this->expectException(InvalidQueryException::class);

        Mikrotik::connection()->menu('interface')->query([], ['name,type']);
    }

    public function test_sensitive_fields_cannot_be_queried_or_listed_by_default(): void
    {
        foreach ([[['password=x'], []], [[], ['password']]] as [$w, $p]) {
            try {
                Mikrotik::connection()->menu('user')->query($w, $p);
                $this->fail('expected InvalidQueryException');
            } catch (InvalidQueryException) {
                $this->assertTrue(true);
            }
        }
        Http::assertNothingSent();
    }

    public function test_menu_reads_strip_credentials_by_default_and_with_secrets_restores_them(): void
    {
        $row = ['.id' => '*1', 'name' => 'u', 'password' => 'leaky-pw', 'wpa2-pre-shared-key' => 'leaky-psk'];
        Http::fake([
            'router.test/rest/ppp/secret' => Http::response([$row], 200),
            'router.test/rest/ppp/secret/*1' => Http::response($row, 200),
            'router.test/rest/ppp/secret/print' => Http::response([$row], 200),
        ]);
        $menu = Mikrotik::connection()->menu('ppp/secret');

        foreach ([$menu->get()[0], $menu->find('*1'), $menu->query(['name=u'])[0]] as $r) {
            $this->assertSame(['.id' => '*1', 'name' => 'u'], $r);
        }

        $this->assertSame('leaky-pw', $menu->withSecrets()->get()[0]['password']);
        $this->assertArrayNotHasKey('password', $menu->get()[0], 'withSecrets() must not mutate the original Menu');
    }
}
