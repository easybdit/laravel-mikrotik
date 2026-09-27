<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Unit;

use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;
use PHPUnit\Framework\TestCase;

class ResponseNormalizerTest extends TestCase
{
    private ResponseNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ResponseNormalizer();
    }

    public function test_normalize_resource_accepts_a_single_object_not_wrapped_in_a_list(): void
    {
        $resource = $this->normalizer->normalizeResource([
            'architecture-name' => 'arm',
            'cpu-count' => '2',
        ]);

        $this->assertSame('arm', $resource->architectureName);
        $this->assertSame(2, $resource->cpuCount);
    }

    public function test_normalize_health_accepts_flat_legacy_style_object(): void
    {
        // Defensive fallback path — see ResponseNormalizer::healthRows() docblock
        // for why this shape is supported without being independently
        // confirmed live against a real device's REST response.
        $reading = $this->normalizer->normalizeHealth([
            'voltage' => '23.8',
            'temperature' => '39',
        ]);

        $this->assertSame(23.8, $reading->get('voltage')->value);
        $this->assertSame(39, $reading->get('temperature')->value);
    }

    public function test_normalize_health_on_completely_empty_array_returns_empty_reading(): void
    {
        $reading = $this->normalizer->normalizeHealth([]);

        $this->assertTrue($reading->isEmpty());
    }

    public function test_float_values_are_distinguished_from_integers(): void
    {
        $reading = $this->normalizer->normalizeHealth([
            ['name' => 'a', 'value' => '10'],
            ['name' => 'b', 'value' => '10.5'],
        ]);

        $this->assertIsInt($reading->get('a')->value);
        $this->assertIsFloat($reading->get('b')->value);
    }

    public function test_normalize_logs_accepts_a_single_object_not_wrapped_in_a_list(): void
    {
        $logs = $this->normalizer->normalizeLogs([
            'time' => '12:00:00', 'topics' => 'info', 'message' => 'single record',
        ]);

        $this->assertSame(1, $logs->count());
        $this->assertSame('single record', $logs->all()[0]->message);
    }

    public function test_normalize_interfaces_accepts_single_objects_not_wrapped_in_lists(): void
    {
        $interfaces = $this->normalizer->normalizeInterfaces(
            ['name' => 'ether1', 'type' => 'ether'],
            ['name' => 'ether1', 'rx-byte' => '10'],
        );

        $ether1 = $interfaces->get('ether1');

        $this->assertSame('ether', $ether1->type);
        $this->assertSame(10, $ether1->rxByte);
    }
}
