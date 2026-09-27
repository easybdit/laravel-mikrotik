<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class InterfaceTest extends TestCase
{
    private const IDENTITY_URL = 'router.test/rest/interface';
    private const STATS_URL = 'router.test/rest/interface/print';

    private function fakeIdentity(array $rows): void
    {
        Http::fake([self::IDENTITY_URL => Http::response($rows, 200)]);
    }

    private function fakeStats(array $rows): void
    {
        Http::fake([self::STATS_URL => Http::response($rows, 200)]);
    }

    public function test_normal_response_merges_identity_and_stats_by_name(): void
    {
        $this->fakeIdentity([
            [
                'name'        => 'ether1',
                'type'        => 'ether',
                'running'     => 'true',
                'disabled'    => 'false',
                'mtu'         => '1500',
                'mac-address' => 'AA:BB:CC:DD:EE:FF',
                'comment'     => 'WAN',
            ],
        ]);
        $this->fakeStats([
            [
                'name'      => 'ether1',
                'rx-byte'   => '205164277',
                'tx-byte'   => '147977500',
                'rx-packet' => '158254',
                'tx-packet' => '150156',
                'link-downs' => '2',
            ],
        ]);

        $interfaces = Mikrotik::connection()->interfaces();
        $eth1 = $interfaces->get('ether1');

        $this->assertNotNull($eth1);
        $this->assertSame('ether1', $eth1->name);
        $this->assertSame('ether', $eth1->type);
        $this->assertTrue($eth1->running);
        $this->assertFalse($eth1->disabled);
        $this->assertSame(1500, $eth1->mtu);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $eth1->macAddress);
        $this->assertSame(205164277, $eth1->rxByte);
        $this->assertIsInt($eth1->rxByte);
        $this->assertSame(150156, $eth1->txPacket);
        $this->assertSame(2, $eth1->linkDowns);
    }

    public function test_multiple_interfaces_are_all_present(): void
    {
        $this->fakeIdentity([
            ['name' => 'ether1', 'type' => 'ether', 'running' => 'true', 'disabled' => 'false'],
            ['name' => 'ether2', 'type' => 'ether', 'running' => 'false', 'disabled' => 'true'],
        ]);
        $this->fakeStats([
            ['name' => 'ether1', 'rx-byte' => '100'],
            ['name' => 'ether2', 'rx-byte' => '0'],
        ]);

        $interfaces = Mikrotik::connection()->interfaces();

        $this->assertSame(2, $interfaces->count());
        $this->assertTrue($interfaces->has('ether1'));
        $this->assertTrue($interfaces->has('ether2'));
    }

    public function test_disabled_and_not_running_interface_is_normalized_correctly(): void
    {
        $this->fakeIdentity([
            ['name' => 'ether5', 'type' => 'ether', 'running' => 'false', 'disabled' => 'true'],
        ]);
        $this->fakeStats([
            ['name' => 'ether5', 'rx-byte' => '0', 'tx-byte' => '0'],
        ]);

        $ether5 = Mikrotik::connection()->interfaces()->get('ether5');

        $this->assertFalse($ether5->running);
        $this->assertTrue($ether5->disabled);
    }

    public function test_running_interface_is_normalized_correctly(): void
    {
        $this->fakeIdentity([
            ['name' => 'ether1', 'running' => 'true', 'disabled' => 'false'],
        ]);
        $this->fakeStats([['name' => 'ether1']]);

        $ether1 = Mikrotik::connection()->interfaces()->get('ether1');

        $this->assertTrue($ether1->running);
        // Regression guard: "false" must never blindly (bool)-cast to true.
        $this->assertFalse($ether1->disabled);
    }

    public function test_missing_optional_fields_do_not_throw_and_are_null(): void
    {
        $this->fakeIdentity([
            ['name' => 'ether1'],
        ]);
        $this->fakeStats([
            ['name' => 'ether1'],
        ]);

        $ether1 = Mikrotik::connection()->interfaces()->get('ether1');

        $this->assertSame('ether1', $ether1->name);
        $this->assertNull($ether1->type);
        $this->assertNull($ether1->running);
        $this->assertNull($ether1->macAddress);
        $this->assertNull($ether1->rxByte);
        $this->assertNull($ether1->linkDowns);
    }

    public function test_unknown_field_is_preserved_in_raw_without_breaking_normalization(): void
    {
        $this->fakeIdentity([
            ['name' => 'ether1', 'some-future-field' => 'value'],
        ]);
        $this->fakeStats([['name' => 'ether1']]);

        $ether1 = Mikrotik::connection()->interfaces()->get('ether1');

        $this->assertSame('ether1', $ether1->name);
        $this->assertArrayHasKey('some-future-field', $ether1->raw);
    }

    public function test_zero_counters_are_not_treated_as_missing(): void
    {
        $this->fakeIdentity([['name' => 'ether1']]);
        $this->fakeStats([
            ['name' => 'ether1', 'rx-byte' => '0', 'tx-byte' => '0', 'rx-packet' => '0'],
        ]);

        $ether1 = Mikrotik::connection()->interfaces()->get('ether1');

        $this->assertSame(0, $ether1->rxByte);
        $this->assertIsInt($ether1->rxByte);
        $this->assertNotNull($ether1->rxByte);
    }

    public function test_large_counter_values_are_preserved_as_integers(): void
    {
        $this->fakeIdentity([['name' => 'ether1']]);
        $this->fakeStats([
            ['name' => 'ether1', 'rx-byte' => '9223372036854775'],
        ]);

        $ether1 = Mikrotik::connection()->interfaces()->get('ether1');

        $this->assertSame(9223372036854775, $ether1->rxByte);
    }

    public function test_interface_present_only_in_stats_is_not_dropped(): void
    {
        // Defensive: if the two calls ever disagree on the interface set,
        // nothing is silently discarded.
        $this->fakeIdentity([]);
        $this->fakeStats([
            ['name' => 'ether1', 'rx-byte' => '10'],
        ]);

        $interfaces = Mikrotik::connection()->interfaces();

        $this->assertTrue($interfaces->has('ether1'));
        $this->assertNull($interfaces->get('ether1')->type);
        $this->assertSame(10, $interfaces->get('ether1')->rxByte);
    }

    public function test_empty_response_returns_empty_collection_not_an_error(): void
    {
        $this->fakeIdentity([]);
        $this->fakeStats([]);

        $interfaces = Mikrotik::connection()->interfaces();

        $this->assertTrue($interfaces->isEmpty());
        $this->assertSame(0, $interfaces->count());
    }

    public function test_stats_call_is_sent_as_post_with_stats_detail_argument(): void
    {
        $this->fakeIdentity([]);
        $this->fakeStats([]);

        Mikrotik::connection()->interfaces();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://router.test/rest/interface/print'
                && $request->method() === 'POST'
                && $request['stats-detail'] === '';
        });
    }
}
