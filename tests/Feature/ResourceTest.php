<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Easybdit\LaravelMikrotik\Facades\Mikrotik;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class ResourceTest extends TestCase
{
    private const RESOURCE_URL = 'router.test/rest/system/resource';

    public function test_valid_response_is_normalized_correctly(): void
    {
        Http::fake([
            self::RESOURCE_URL => Http::response([[
                'architecture-name' => 'arm64',
                'board-name'        => 'CCR2004-16G-2S+',
                'platform'          => 'MikroTik',
                'cpu'               => 'ARM64',
                'cpu-count'         => '4',
                'cpu-frequency'     => '1700',
                'cpu-load'          => '3',
                'free-memory'       => '1503133696',
                'total-memory'      => '2046820352',
                'free-hdd-space'    => '83439616',
                'total-hdd-space'   => '134217728',
                'uptime'            => '2d20h12m20s',
                'version'           => '7.15 (stable)',
                'build-time'        => 'Dec/04/2020 14:19:51',
            ]], 200),
        ]);

        $resource = Mikrotik::connection()->resource();

        $this->assertSame('arm64', $resource->architectureName);
        $this->assertSame('CCR2004-16G-2S+', $resource->boardName);
        $this->assertSame(4, $resource->cpuCount);
        $this->assertIsInt($resource->cpuCount);
        $this->assertSame(1700, $resource->cpuFrequency);
        $this->assertSame(3, $resource->cpuLoad);
        $this->assertSame(1503133696, $resource->freeMemory);
        $this->assertSame('2d20h12m20s', $resource->uptime);
        $this->assertSame('7.15 (stable)', $resource->version);
    }

    public function test_missing_optional_field_does_not_throw_and_is_null(): void
    {
        Http::fake([
            self::RESOURCE_URL => Http::response([[
                'architecture-name' => 'tile',
                'version'           => '7.1beta4',
                // board-name, cpu-count, etc. intentionally absent
            ]], 200),
        ]);

        $resource = Mikrotik::connection()->resource();

        $this->assertSame('tile', $resource->architectureName);
        $this->assertNull($resource->boardName);
        $this->assertNull($resource->cpuCount);
        $this->assertNull($resource->freeHddSpace);
    }

    public function test_unexpected_field_is_preserved_in_raw_without_breaking_normalization(): void
    {
        Http::fake([
            self::RESOURCE_URL => Http::response([[
                'architecture-name' => 'arm',
                'version'           => '7.16',
                'some-brand-new-field-future-routeros-adds' => 'unexpected-value',
            ]], 200),
        ]);

        $resource = Mikrotik::connection()->resource();

        $this->assertSame('arm', $resource->architectureName);
        $this->assertArrayHasKey('some-brand-new-field-future-routeros-adds', $resource->raw);
        $this->assertSame('unexpected-value', $resource->raw['some-brand-new-field-future-routeros-adds']);
    }

    public function test_non_numeric_value_in_a_numeric_field_is_not_blindly_cast(): void
    {
        Http::fake([
            self::RESOURCE_URL => Http::response([[
                'architecture-name' => 'arm',
                'cpu-count'         => 'unknown', // malformed/unexpected upstream value
            ]], 200),
        ]);

        $resource = Mikrotik::connection()->resource();

        // A field documented as numeric that arrives non-numeric is not
        // guessed at — it is treated as unavailable (null), never silently
        // coerced to 0 or left as a raw string in a typed int property.
        $this->assertNull($resource->cpuCount);
    }

    public function test_routeros_error_response_throws_router_os_exception(): void
    {
        Http::fake([
            self::RESOURCE_URL => Http::response(
                ['error' => 500, 'message' => 'Internal Server Error', 'detail' => 'something failed'],
                500
            ),
        ]);

        $this->expectException(RouterOsException::class);

        Mikrotik::connection()->resource();
    }
}
