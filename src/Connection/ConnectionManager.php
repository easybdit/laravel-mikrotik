<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Connection;

use Easybdit\LaravelMikrotik\Exceptions\InvalidConfigurationException;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;
use Easybdit\LaravelMikrotik\Transport\RestTransport;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Resolves named connections defined under config('mikrotik.connections'),
 * mirroring the same "named connection" idiom Laravel itself uses for
 * config('database.connections') — see Illuminate\Support\Manager, which
 * this class deliberately follows the spirit of without inheriting from
 * it, since Manager is built around "one driver type per app" (like
 * Cache/Queue) rather than "many named instances of the same driver"
 * (like Database), which is what multiple routers actually need.
 */
final class ConnectionManager
{
    /** @var array<string, RouterConnection> */
    private array $resolved = [];

    public function __construct(private readonly ConfigRepository $config)
    {
    }

    /**
     * Resolve (and cache) a named connection. Omit $name to use the
     * connection configured as config('mikrotik.default').
     */
    public function connection(?string $name = null): RouterConnection
    {
        $name ??= $this->getDefaultConnectionName();

        return $this->resolved[$name] ??= $this->makeConnection($name);
    }

    public function getDefaultConnectionName(): string
    {
        return (string) $this->config->get('mikrotik.default', 'default');
    }

    private function makeConnection(string $name): RouterConnection
    {
        $config = $this->config->get("mikrotik.connections.{$name}");

        if (!is_array($config)) {
            throw InvalidConfigurationException::unknownConnection($name);
        }

        $transportName = $config['transport'] ?? 'rest';

        if ($transportName !== 'rest') {
            // P1 supports the REST transport only. The Transport contract
            // and this switch point are what let a future "api" (binary
            // protocol) transport be added without changing RouterConnection.
            throw InvalidConfigurationException::unsupportedTransport($name, (string) $transportName);
        }

        foreach (['host', 'username', 'password'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $config) || $config[$requiredKey] === null) {
                throw InvalidConfigurationException::missingKey($name, $requiredKey);
            }
        }

        $retry = is_array($config['retry'] ?? null) ? $config['retry'] : [];

        $transport = new RestTransport(
            host: (string) $config['host'],
            port: (int) ($config['port'] ?? 443),
            username: (string) $config['username'],
            password: (string) $config['password'],
            verifyTls: (bool) ($config['verify_tls'] ?? true),
            // Clamped, not passed through raw (P11): Guzzle's own
            // documented "timeout" semantics treat 0 as "wait
            // indefinitely" (confirmed directly from
            // GuzzleHttp\RequestOptions), so a misconfigured
            // MIKROTIK_TIMEOUT=0 would otherwise hang forever against an
            // unresponsive router. The upper bound is set just above
            // RouterOS REST's own documented ~60s command timeout
            // (help.mikrotik.com "REST API"), so the client can still
            // receive RouterOS's own timeout error rather than cutting
            // the connection first.
            timeoutSeconds: self::clamp((int) ($config['timeout'] ?? 10), min: 1, max: 120),
            retryTimes: self::clamp((int) ($retry['times'] ?? 0), min: 0, max: 10),
            retrySleepMilliseconds: self::clamp((int) ($retry['sleep'] ?? 0), min: 0, max: 30000),
        );

        return new RouterConnection($name, $transport, new ResponseNormalizer());
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
