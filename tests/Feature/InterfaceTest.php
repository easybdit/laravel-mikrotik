<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class InterfaceTest extends TestCase
{
    private const INTERFACE_URL = 'router.test/rest/interface';

    private function fake(array $rows): void
    {
        Http::fake([self::INTERFACE_URL => Http::response($rows, 200)]);
    }

    public function test_normal_response_is_normalized_correctly(): void
    {
        // Shape confirmed against a real device (RouterOS 7.10.2,
        // RB3011UiAS): a single GET /rest/interface call already returns
        // every field below, including counters.
        $this->fake([
            [
                'name'        => 'ether1',
                'type'        => 'ether',
                'running'     => 'true',
                'disabled'    => 'false',
                'mtu'         => '1500',
                'mac-address' => 'AA:BB:CC:DD:EE:FF',
                'comment'     => 'WAN',
                'rx-byte'     => '205164277',
                'tx-byte'     => '147977500',
                'rx-packet'   => '158254',
                'tx-packet'   => '150156',
                'rx-error'    => '0',
                'tx-error'    => '0',
                'rx-drop'     => '0',
                'tx-drop'     => '0',
                'tx-queue-drop' => '2',
                'link-downs'  => '2',
            ],
        ]);

        $eth1 = Mikrotik::connection()->interfaces()->get('ether1');

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
        $this->assertSame(0, $eth1->rxError);
        $this->assertSame(0, $eth1->txError);
        $this->assertSame(0, $eth1->rxDrop);
        $this->assertSame(0, $eth1->txDrop);
        $this->assertSame(2, $eth1->txQueueDrop);
        $this->assertSame(2, $eth1->linkDowns);
    }

    public function test_multiple_interfaces_are_all_present(): void
    {
        $this->fake([
            ['name' => 'ether1', 'type' => 'ether', 'running' => 'true', 'disabled' => 'false'],
            ['name' => 'ether2', 'type' => 'ether', 'running' => 'false', 'disabled' => 'true'],
        ]);

        $interfaces = Mikrotik::connection()->interfaces();

        $this->assertSame(2, $interfaces->count());
        $this->assertTrue($interfaces->has('ether1'));
        $this->assertTrue($interfaces->has('ether2'));
    }

    public function test_disabled_and_not_running_interface_is_normalized_correctly(): void
    {
        $this->fake([
            ['name' => 'ether5', 'type' => 'ether', 'running' => 'false', 'disabled' => 'true'],
        ]);

        $ether5 = Mikrotik::connection()->interfaces()->get('ether5');

        $this->assertFalse($ether5->running);
        $this->assertTrue($ether5->disabled);
    }

    public function test_running_interface_is_normalized_correctly(): void
    {
        $this->fake([
            ['name' => 'ether1', 'running' => 'true', 'disabled' => 'false'],
        ]);

        $ether1 = Mikrotik::connection()->interfaces()->get('ether1');

        $this->assertTrue($ether1->running);
        // Regression guard: "false" must never blindly (bool)-cast to true.
        $this->assertFalse($ether1->disabled);
    }

    public function test_missing_optional_fields_do_not_throw_and_are_null(): void
    {
        $this->fake([
            ['name' => 'ether1'],
        ]);

        $ether1 = Mikrotik::connection()->interfaces()->get('ether1');

        $this->assertSame('ether1', $ether1->name);
        $this->assertNull($ether1->type);
        $this->assertNull($ether1->running);
        $this->assertNull($ether1->macAddress);
        $this->assertNull($ether1->rxByte);
        $this->assertNull($ether1->rxError);
        $this->assertNull($ether1->rxDrop);
        $this->assertNull($ether1->linkDowns);
    }

    public function test_unknown_field_is_preserved_in_raw_without_breaking_normalization(): void
    {
        $this->fake([
            ['name' => 'ether1', 'some-future-field' => 'value'],
        ]);

        $ether1 = Mikrotik::connection()->interfaces()->get('ether1');

        $this->assertSame('ether1', $ether1->name);
        $this->assertArrayHasKey('some-future-field', $ether1->raw);
    }

    public function test_zero_counters_are_not_treated_as_missing(): void
    {
        $this->fake([
            ['name' => 'ether1', 'rx-byte' => '0', 'tx-byte' => '0', 'rx-packet' => '0'],
        ]);

        $ether1 = Mikrotik::connection()->interfaces()->get('ether1');

        $this->assertSame(0, $ether1->rxByte);
        $this->assertIsInt($ether1->rxByte);
        $this->assertNotNull($ether1->rxByte);
    }

    public function test_large_counter_values_are_preserved_as_integers(): void
    {
        $this->fake([
            ['name' => 'ether1', 'rx-byte' => '9223372036854775'],
        ]);

        $ether1 = Mikrotik::connection()->interfaces()->get('ether1');

        $this->assertSame(9223372036854775, $ether1->rxByte);
    }

    public function test_non_numeric_mtu_is_not_blindly_cast(): void
    {
        // Real device confirmed a bridge interface can report mtu="auto".
        $this->fake([
            ['name' => 'Lan_Bridge', 'type' => 'bridge', 'mtu' => 'auto'],
        ]);

        $bridge = Mikrotik::connection()->interfaces()->get('Lan_Bridge');

        $this->assertNull($bridge->mtu);
    }

    public function test_empty_response_returns_empty_collection_not_an_error(): void
    {
        $this->fake([]);

        $interfaces = Mikrotik::connection()->interfaces();

        $this->assertTrue($interfaces->isEmpty());
        $this->assertSame(0, $interfaces->count());
    }

    public function test_interfaces_uses_a_single_get_request(): void
    {
        $this->fake([]);

        Mikrotik::connection()->interfaces();

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://router.test/rest/interface'
                && $request->method() === 'GET';
        });
    }
}
