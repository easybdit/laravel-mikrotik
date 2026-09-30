<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Transport;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\Exceptions\AuthenticationException;
use Easybdit\LaravelMikrotik\Exceptions\ConnectionException as MikrotikConnectionException;
use Easybdit\LaravelMikrotik\Exceptions\MalformedResponseException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\RequestException as HttpRequestException;
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
 *
 * GET vs POST (source: help.mikrotik.com "REST API" documentation): GET
 * maps to a console "print" and accepts simple equality filters as URL
 * query-string parameters (e.g. "?type=ether"). POST accepts arbitrary
 * console command arguments as JSON body keys (e.g. POST .../print with
 * body {"stats-detail":""}) — this is the only documented way to pass an
 * argument GET's query string can't express. The same documentation
 * states there is no way to run a continuous/streaming console command
 * (e.g. "monitor") over REST at all; this class does not attempt to.
 *
 * Retry (P9, opt-in): $retryTimes defaults to 0 (no retry at all —
 * identical to every version of this class before P9). When set above
 * 0, a request is retried only for a transient connection failure
 * (timeout/DNS/refused — the same condition ConnectionException::
 * timedOut()/unreachable() already represent) or a RouterOS-side 5xx
 * response; never for a 401/403 (AuthenticationException) or any other
 * 4xx (a RouterOS-side rejection like a bad query, not a transient
 * fault) — retrying those would not change the outcome and would risk
 * hammering credentials that are simply wrong. Retrying is otherwise
 * always safe here: P9 added no write operations, and GET/this class's
 * one documented POST use (monitor-traffic "once") are both idempotent.
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
        private readonly int $retryTimes = 0,
        private readonly int $retrySleepMilliseconds = 0,
    ) {
    }

    public function get(string $path, array $query = []): array
    {
        $path = $this->normalizePath($path);

        return $this->send($path, fn () => $this->client()->get($this->url($path), $query));
    }

    public function post(string $path, array $body = []): array
    {
        $path = $this->normalizePath($path);

        return $this->send($path, fn () => $this->client()->post($this->url($path), $body));
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::withBasicAuth($this->username, $this->password)
            ->withOptions(['verify' => $this->verifyTls])
            ->timeout($this->timeoutSeconds)
            ->acceptJson();

        if ($this->retryTimes > 0) {
            // throw:false is deliberate -- retry() must still hand back a
            // plain (possibly still-failing) Response after retries are
            // exhausted, so send()'s existing status-code handling below
            // decides the final outcome exactly as it already does today;
            // this only adds retry attempts in front of that, unchanged
            // logic.
            $client = $client->retry(
                $this->retryTimes,
                $this->retrySleepMilliseconds,
                function (\Throwable $exception): bool {
                    if ($exception instanceof HttpConnectionException) {
                        return true;
                    }

                    return $exception instanceof HttpRequestException && $exception->response->status() >= 500;
                },
                throw: false,
            );
        }

        return $client;
    }

    private function url(string $path): string
    {
        return "https://{$this->host}:{$this->port}/rest{$path}";
    }

    private function normalizePath(string $path): string
    {
        return '/' . ltrim($path, '/');
    }

    /**
     * @param \Closure(): \Illuminate\Http\Client\Response $send
     * @return array<string|int, mixed>
     */
    private function send(string $path, \Closure $send): array
    {
        try {
            $response = $send();
        } catch (HttpConnectionException $e) {
            if ($this->isTimeout($e)) {
                throw MikrotikConnectionException::timedOut($this->hostLabel(), $this->timeoutSeconds);
            }

            throw MikrotikConnectionException::unreachable($this->hostLabel(), $this->sanitizedPrevious($e));
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

    /**
     * Laravel's HTTP client does not expose a distinct timeout exception
     * type — a timeout and a plain connection failure both surface as
     * Illuminate\Http\Client\ConnectionException, so the underlying cURL
     * error message is the only signal available to tell them apart.
     * Guzzle's CurlFactory builds this message itself from libcurl's own
     * (locale-independent) error string, so matching on it is a stable —
     * if inelegant — heuristic, not a fragile guess.
     */
    private function isTimeout(HttpConnectionException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'timed out')
            || str_contains(strtolower($e->getMessage()), 'timeout');
    }

    /**
     * Strips the original exception down to a bare RuntimeException
     * carrying only its message.
     *
     * $e (an Illuminate\Http\Client\ConnectionException) wraps a
     * GuzzleHttp\Exception\ConnectException, whose getRequest() returns
     * the complete outgoing PSR-7 request — including the
     * "Authorization: Basic ..." header this class sent. Chaining $e
     * directly as $previous would keep that request reachable from this
     * package's own exception (via getPrevious()->getPrevious()->getRequest()),
     * which an application-level error tracker could serialize. The
     * message text itself is safe to keep: Guzzle/cURL error messages
     * describe the failure (DNS, refused, timeout) and the request URL,
     * never the Authorization header's contents.
     */
    private function sanitizedPrevious(HttpConnectionException $e): \RuntimeException
    {
        return new \RuntimeException($e->getMessage());
    }
}
