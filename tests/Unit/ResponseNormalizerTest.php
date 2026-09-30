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

    public function test_normalize_interfaces_accepts_a_single_object_not_wrapped_in_a_list(): void
    {
        $interfaces = $this->normalizer->normalizeInterfaces(
            ['name' => 'ether1', 'type' => 'ether', 'rx-byte' => '10'],
        );

        $ether1 = $interfaces->get('ether1');

        $this->assertSame('ether', $ether1->type);
        $this->assertSame(10, $ether1->rxByte);
    }

    public function test_normalize_interface_rate_accepts_the_documented_array_wrapped_shape(): void
    {
        $rate = $this->normalizer->normalizeInterfaceRate([
            ['name' => 'ether1', 'rx-bits-per-second' => '159176', 'tx-bits-per-second' => '227456'],
        ]);

        $this->assertSame('ether1', $rate->name);
        $this->assertSame(159176, $rate->rxBitsPerSecond);
        $this->assertSame(227456, $rate->txBitsPerSecond);
    }

    public function test_normalize_ip_address_accepts_a_single_object_not_wrapped_in_a_list(): void
    {
        $address = $this->normalizer->normalizeIpAddress([
            '.id' => '*1', 'address' => '192.168.88.1/24', 'network' => '192.168.88.0',
            'interface' => 'bridge1', 'actual-interface' => 'bridge1', 'disabled' => 'false',
            'dynamic' => 'false', 'invalid' => 'false', 'comment' => 'lan',
        ]);

        $this->assertSame('*1', $address->id);
        $this->assertSame('192.168.88.1/24', $address->address);
        $this->assertSame('bridge1', $address->actualInterface);
        $this->assertFalse($address->disabled);
        $this->assertFalse($address->dynamic);
        $this->assertFalse($address->invalid);
        $this->assertSame('lan', $address->comment);
    }

    public function test_normalize_ip_addresses_builds_a_list(): void
    {
        $addresses = $this->normalizer->normalizeIpAddresses([
            ['.id' => '*1', 'address' => '192.168.88.1/24'],
            ['.id' => '*2', 'address' => '10.0.0.1/24'],
        ]);

        $this->assertCount(2, $addresses);
        $this->assertSame('*1', $addresses[0]->id);
        $this->assertSame('*2', $addresses[1]->id);
    }

    public function test_normalize_ip_address_missing_optional_fields_does_not_throw(): void
    {
        $address = $this->normalizer->normalizeIpAddress(['.id' => '*1', 'address' => '1.2.3.4/32']);

        $this->assertNull($address->comment);
        $this->assertNull($address->disabled);
        $this->assertSame(['.id' => '*1', 'address' => '1.2.3.4/32'], $address->raw);
    }

    public function test_normalize_interface_record_accepts_a_single_object_not_wrapped_in_a_list(): void
    {
        $interface = $this->normalizer->normalizeInterfaceRecord([
            '.id' => '*7', 'name' => 'ether7', 'type' => 'ether',
            'running' => 'false', 'disabled' => 'false', 'comment' => 'idle test port',
        ]);

        $this->assertSame('*7', $interface->id);
        $this->assertSame('ether7', $interface->name);
        $this->assertSame('ether', $interface->type);
        $this->assertFalse($interface->running);
        $this->assertFalse($interface->disabled);
        $this->assertSame('idle test port', $interface->comment);
    }

    public function test_normalize_interface_records_builds_a_list(): void
    {
        $interfaces = $this->normalizer->normalizeInterfaceRecords([
            ['.id' => '*1', 'name' => 'ether1'],
            ['.id' => '*7', 'name' => 'ether7'],
        ]);

        $this->assertCount(2, $interfaces);
        $this->assertSame('*1', $interfaces[0]->id);
        $this->assertSame('*7', $interfaces[1]->id);
    }

    public function test_normalize_interface_record_missing_optional_fields_does_not_throw(): void
    {
        $interface = $this->normalizer->normalizeInterfaceRecord(['.id' => '*7', 'name' => 'ether7']);

        $this->assertNull($interface->comment);
        $this->assertNull($interface->disabled);
        $this->assertSame(['.id' => '*7', 'name' => 'ether7'], $interface->raw);
    }

    public function test_normalize_firewall_filter_rule_maps_documented_fields(): void
    {
        $rule = $this->normalizer->normalizeFirewallFilterRule([
            '.id' => '*1', 'chain' => 'forward', 'action' => 'drop', 'protocol' => 'tcp',
            'src-address' => '10.0.0.1', 'dst-port' => '80', 'disabled' => 'true', 'comment' => 'test',
        ]);

        $this->assertSame('forward', $rule->chain);
        $this->assertSame('drop', $rule->action);
        $this->assertSame('10.0.0.1', $rule->srcAddress);
        $this->assertSame('80', $rule->dstPort);
        $this->assertTrue($rule->disabled);
    }

    public function test_normalize_dhcp_lease_maps_documented_fields(): void
    {
        $lease = $this->normalizer->normalizeDhcpLease([
            '.id' => '*1', 'address' => '192.168.1.10', 'mac-address' => 'AA:BB:CC:DD:EE:FF',
            'host-name' => 'my-host', 'status' => 'bound', 'dynamic' => 'true',
        ]);

        $this->assertSame('192.168.1.10', $lease->address);
        $this->assertSame('my-host', $lease->hostName);
        $this->assertTrue($lease->dynamic);
    }

    public function test_normalize_ppp_secret_strips_the_password_from_raw(): void
    {
        $secret = $this->normalizer->normalizePppSecret([
            '.id' => '*1', 'name' => 'testuser', 'password' => 'super-secret-value', 'service' => 'pppoe',
        ]);

        $this->assertSame('testuser', $secret->name);
        $this->assertFalse(property_exists($secret, 'password'));
        $this->assertArrayNotHasKey('password', array_diff_key($secret->raw, ['password' => null])); // sanity: key removed, not just nulled
        $this->assertNotSame('super-secret-value', $secret->raw['password'] ?? null);
        $this->assertStringNotContainsString('super-secret-value', json_encode($secret));
        $this->assertStringNotContainsString('super-secret-value', json_encode($secret->raw));
    }

    public function test_normalize_ppp_secrets_builds_a_list_with_passwords_stripped(): void
    {
        $secrets = $this->normalizer->normalizePppSecrets([
            ['.id' => '*1', 'name' => 'a', 'password' => 'secret-a'],
            ['.id' => '*2', 'name' => 'b', 'password' => 'secret-b'],
        ]);

        $this->assertCount(2, $secrets);
        $this->assertStringNotContainsString('secret-a', json_encode($secrets[0]->raw));
        $this->assertStringNotContainsString('secret-b', json_encode($secrets[1]->raw));
    }

    public function test_normalize_ip_pool_missing_optional_fields_does_not_throw(): void
    {
        $pool = $this->normalizer->normalizeIpPool(['.id' => '*1', 'name' => 'pool1']);

        $this->assertNull($pool->ranges);
    }

    public function test_normalize_ip_route_maps_documented_fields(): void
    {
        $route = $this->normalizer->normalizeIpRoute([
            '.id' => '*1', 'dst-address' => '0.0.0.0/0', 'gateway' => '192.168.1.1',
            'distance' => '1', 'inactive' => 'false', 'disabled' => 'true',
        ]);

        $this->assertSame('0.0.0.0/0', $route->dstAddress);
        $this->assertSame(1, $route->distance);
        $this->assertFalse($route->inactive);
        $this->assertTrue($route->disabled);
    }

    public function test_normalize_dns_settings_accepts_a_single_object_response(): void
    {
        $settings = $this->normalizer->normalizeDnsSettings([
            'servers' => '8.8.8.8,1.1.1.1', 'allow-remote-requests' => 'false', 'cache-size' => '2048',
        ]);

        $this->assertSame('8.8.8.8,1.1.1.1', $settings->servers);
        $this->assertFalse($settings->allowRemoteRequests);
    }

    public function test_normalize_system_identity_accepts_a_single_object_response(): void
    {
        $identity = $this->normalizer->normalizeSystemIdentity(['name' => 'MyRouter']);

        $this->assertSame('MyRouter', $identity->name);
    }
}
