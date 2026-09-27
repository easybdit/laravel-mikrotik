<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\MalformedResponseException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class HealthTest extends TestCase
{
    private const HEALTH_URL = 'router.test/rest/system/health';

    public function test_multiple_sensor_types_are_normalized(): void
    {
        Http::fake([
            self::HEALTH_URL => Http::response([
                ['name' => 'cpu-temperature', 'value' => '43', 'type' => 'C'],
                ['name' => 'psu1-voltage', 'value' => '0', 'type' => 'V'],
                ['name' => 'psu2-voltage', 'value' => '12.1', 'type' => 'V'],
                ['name' => 'fan1-speed', 'value' => '5654', 'type' => 'RPM'],
            ], 200),
        ]);

        $health = Mikrotik::connection()->health();

        $this->assertSame(43, $health->get('cpu-temperature')->value);
        $this->assertSame('C', $health->get('cpu-temperature')->type);
        $this->assertSame(12.1, $health->get('psu2-voltage')->value);
        $this->assertSame(5654, $health->get('fan1-speed')->value);
    }

    public function test_a_device_exposing_only_two_sensors_does_not_throw(): void
    {
        // Confirmed by MikroTik documentation: some boards expose only
        // "voltage" and "temperature", never the full CCR-style sensor set.
        Http::fake([
            self::HEALTH_URL => Http::response([
                ['name' => 'voltage', 'value' => '23.8', 'type' => 'V'],
                ['name' => 'temperature', 'value' => '39', 'type' => 'C'],
            ], 200),
        ]);

        $health = Mikrotik::connection()->health();

        $this->assertTrue($health->has('voltage'));
        $this->assertFalse($health->has('cpu-temperature'));
        $this->assertNull($health->get('cpu-temperature'));
    }

    public function test_unknown_sensor_name_is_preserved_and_does_not_throw(): void
    {
        Http::fake([
            self::HEALTH_URL => Http::response([
                ['name' => 'some-future-sensor-not-in-any-doc', 'value' => '99', 'type' => 'X'],
            ], 200),
        ]);

        $health = Mikrotik::connection()->health();
        $sensor = $health->get('some-future-sensor-not-in-any-doc');

        $this->assertNotNull($sensor);
        $this->assertSame(99, $sensor->value);
        $this->assertSame('X', $sensor->type);
        $this->assertSame('99', $sensor->rawValue);
    }

    public function test_status_style_sensor_value_is_preserved_not_miscast_as_numeric(): void
    {
        Http::fake([
            self::HEALTH_URL => Http::response([
                ['name' => 'psu1-state', 'value' => 'not-present'],
            ], 200),
        ]);

        $sensor = Mikrotik::connection()->health()->get('psu1-state');

        $this->assertSame('not-present', $sensor->value);
        $this->assertSame('not-present', $sensor->status);
    }

    public function test_empty_health_response_returns_empty_reading_not_an_error(): void
    {
        Http::fake([
            self::HEALTH_URL => Http::response([], 200),
        ]);

        $health = Mikrotik::connection()->health();

        $this->assertTrue($health->isEmpty());
        $this->assertSame([], $health->all());
    }

    public function test_malformed_json_body_throws_malformed_response_exception(): void
    {
        Http::fake([
            self::HEALTH_URL => Http::response('not json at all {{{', 200),
        ]);

        $this->expectException(MalformedResponseException::class);

        Mikrotik::connection()->health();
    }
}
