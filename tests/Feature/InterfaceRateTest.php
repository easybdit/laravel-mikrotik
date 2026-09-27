<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\MalformedResponseException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class InterfaceRateTest extends TestCase
{
    private const MONITOR_TRAFFIC_URL = 'router.test/rest/interface/monitor-traffic';

    /**
     * Exact response captured against a real device (RouterOS 7.10.2,
     * RB3011UiAS) for POST /rest/interface/monitor-traffic with body
     * {"interface":"Ether1_Primary_PIS","once":""}.
     */
    private function realResponseRow(): array
    {
        return [
            'name'                       => 'Ether1_Primary_PIS',
            'rx-bits-per-second'         => '159176',
            'tx-bits-per-second'         => '227456',
            'rx-packets-per-second'      => '182',
            'tx-packets-per-second'      => '37',
            'rx-errors-per-second'       => '0',
            'tx-errors-per-second'       => '0',
            'rx-drops-per-second'        => '0',
            'tx-drops-per-second'        => '0',
            'tx-queue-drops-per-second'  => '0',
            'fp-rx-bits-per-second'      => '135416',
            'fp-tx-bits-per-second'      => '0',
            'fp-rx-packets-per-second'   => '182',
            'fp-tx-packets-per-second'   => '0',
        ];
    }

    public function test_real_device_response_is_normalized_correctly(): void
    {
        Http::fake([self::MONITOR_TRAFFIC_URL => Http::response([$this->realResponseRow()], 200)]);

        $rate = Mikrotik::connection()->interfaceRate('Ether1_Primary_PIS');

        $this->assertSame('Ether1_Primary_PIS', $rate->name);
        $this->assertSame(159176, $rate->rxBitsPerSecond);
        $this->assertIsInt($rate->rxBitsPerSecond);
        $this->assertSame(227456, $rate->txBitsPerSecond);
        $this->assertSame(182, $rate->rxPacketsPerSecond);
        $this->assertSame(37, $rate->txPacketsPerSecond);
        $this->assertSame(0, $rate->rxErrorsPerSecond);
        $this->assertSame(0, $rate->txErrorsPerSecond);
        $this->assertSame(0, $rate->rxDropsPerSecond);
        $this->assertSame(0, $rate->txDropsPerSecond);
        $this->assertSame(0, $rate->txQueueDropsPerSecond);
        $this->assertSame(135416, $rate->fpRxBitsPerSecond);
        $this->assertSame(0, $rate->fpTxBitsPerSecond);
        $this->assertSame(182, $rate->fpRxPacketsPerSecond);
        $this->assertSame(0, $rate->fpTxPacketsPerSecond);
    }

    public function test_request_uses_the_exact_verified_method_endpoint_and_body(): void
    {
        Http::fake([self::MONITOR_TRAFFIC_URL => Http::response([$this->realResponseRow()], 200)]);

        Mikrotik::connection()->interfaceRate('Ether1_Primary_PIS');

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://router.test/rest/interface/monitor-traffic'
                && $request['interface'] === 'Ether1_Primary_PIS'
                && $request['once'] === '';
        });
    }

    public function test_missing_optional_fields_do_not_throw_and_are_null(): void
    {
        Http::fake([
            self::MONITOR_TRAFFIC_URL => Http::response([
                ['name' => 'ether1', 'rx-bits-per-second' => '100'],
            ], 200),
        ]);

        $rate = Mikrotik::connection()->interfaceRate('ether1');

        $this->assertSame('ether1', $rate->name);
        $this->assertSame(100, $rate->rxBitsPerSecond);
        $this->assertNull($rate->txBitsPerSecond);
        $this->assertNull($rate->fpRxBitsPerSecond);
    }

    public function test_zero_rate_values_are_not_treated_as_missing(): void
    {
        Http::fake([
            self::MONITOR_TRAFFIC_URL => Http::response([
                ['name' => 'ether2', 'rx-bits-per-second' => '0', 'tx-bits-per-second' => '0'],
            ], 200),
        ]);

        $rate = Mikrotik::connection()->interfaceRate('ether2');

        $this->assertSame(0, $rate->rxBitsPerSecond);
        $this->assertIsInt($rate->rxBitsPerSecond);
        $this->assertSame(0, $rate->txBitsPerSecond);
    }

    public function test_raw_response_is_preserved(): void
    {
        $row = $this->realResponseRow();
        Http::fake([self::MONITOR_TRAFFIC_URL => Http::response([$row], 200)]);

        $rate = Mikrotik::connection()->interfaceRate('Ether1_Primary_PIS');

        $this->assertSame($row, $rate->raw);
    }

    public function test_invalid_interface_throws_router_os_exception_with_real_device_detail(): void
    {
        // Exact error captured against a real device for a nonexistent
        // interface name with the correct argument syntax.
        Http::fake([
            self::MONITOR_TRAFFIC_URL => Http::response(
                ['error' => 400, 'message' => 'Bad Request', 'detail' => 'input does not match any value of interface'],
                400
            ),
        ]);

        try {
            Mikrotik::connection()->interfaceRate('this-interface-does-not-exist');
            $this->fail('Expected RouterOsException was not thrown.');
        } catch (RouterOsException $e) {
            $this->assertSame(400, $e->getRouterOsErrorCode());
            $this->assertSame('input does not match any value of interface', $e->getRouterOsDetail());
        }
    }

    public function test_wrong_argument_name_error_from_real_device_also_throws_router_os_exception(): void
    {
        // Real device rejected numbers= and .id= with these exact
        // messages while confirming "interface" as the correct argument.
        Http::fake([
            self::MONITOR_TRAFFIC_URL => Http::response(
                ['error' => 400, 'message' => 'Bad Request', 'detail' => 'unknown parameter numbers'],
                400
            ),
        ]);

        $this->expectException(RouterOsException::class);

        Mikrotik::connection()->interfaceRate('ether1');
    }

    public function test_malformed_json_body_throws_malformed_response_exception(): void
    {
        Http::fake([
            self::MONITOR_TRAFFIC_URL => Http::response('not json at all {{{', 200),
        ]);

        $this->expectException(MalformedResponseException::class);

        Mikrotik::connection()->interfaceRate('ether1');
    }

    public function test_empty_response_normalizes_to_all_null_fields_not_an_error(): void
    {
        Http::fake([self::MONITOR_TRAFFIC_URL => Http::response([], 200)]);

        $rate = Mikrotik::connection()->interfaceRate('ether1');

        $this->assertNull($rate->name);
        $this->assertNull($rate->rxBitsPerSecond);
        $this->assertSame([], $rate->raw);
    }
}
