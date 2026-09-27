<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Transport;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\Exceptions\AuthenticationException;
use Easybdit\LaravelMikrotik\Exceptions\ConnectionException as MikrotikConnectionException;
use Easybdit\LaravelMikrotik\Exceptions\MalformedResponseException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Talks to a RouterOS device's REST API (https://<host>/rest/...).
 *
 * P1 is HTTPS-only by design decision, not merely by default: RouterOS's
 * plain-HTTP "www" REST variant (available since RouterOS v7.9) sends
 * HTTP Basic Auth credentials in the clear, which MikroTik's own
 * documentation advises against for anything but isolated testing. This
 * class does not offer a way to speak plain HTTP; a host/URL is always
 * addressed as "https://...".
 *
 * Minimum RouterOS requirement: v7.1beta4 or later, with the "www-ssl"
 * service enabled (source: help.mikrotik.com "REST API" documentation).
 * Requesting a path against an older RouterOS device will simply fail
 * to connect or 404, surfaced here as ConnectionException / RouterOsException
 * — this class does not attempt to detect RouterOS version up front.
 *
 * This class never logs the request it makes, and in particular never
 * logs the Authorization header, so no code path here can leak
 * credentials into application logs.
 */
class RestTransport implements Transport
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly bool $verifyTls = true,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function get(string $path): array
    {
        $path = '/' . ltrim($path, '/');
        $url  = "https://{$this->host}:{$this->port}/rest{$path}";

        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->withOptions(['verify' => $this->verifyTls])
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->get($url);
        } catch (HttpConnectionException $e) {
            // Laravel's own ConnectionException message may include the
            // request URL (host/port), but never credentials — Basic Auth
            // is sent as a header, not part of the URL, so nothing secret
            // can leak through this rethrow.
            if ($this->isTimeout($e)) {
                throw MikrotikConnectionException::timedOut($this->hostLabel(), $this->timeoutSeconds);
            }

            throw MikrotikConnectionException::unreachable($this->hostLabel(), $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw AuthenticationException::rejected($this->hostLabel(), $response->status());
        }

        if ($response->failed()) {
            $body = $this->decodeOrNull($response->body());

            if (is_array($body) && array_key_exists('message', $body)) {
                throw RouterOsException::fromErrorBody($this->hostLabel(), $response->status(), $body);
            }

            throw RouterOsException::fromErrorBody($this->hostLabel(), $response->status(), [
                'message' => 'Unrecognised error response',
            ]);
        }

        $decoded = $this->decodeOrNull($response->body());

        if (!is_array($decoded)) {
            throw MalformedResponseException::invalidJson($this->hostLabel(), $path);
        }

        return $decoded;
    }

    /**
     * Never returns anything containing username/password — used only
     * for messages surfaced to the caller (exceptions).
     */
    private function hostLabel(): string
    {
        return "{$this->host}:{$this->port}";
    }

    private function decodeOrNull(string $body): mixed
    {
        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function isTimeout(HttpConnectionException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'timed out')
            || str_contains(strtolower($e->getMessage()), 'timeout');
    }
}
