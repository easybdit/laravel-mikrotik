<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Transport;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\Exceptions\AuthenticationException;
use Easybdit\LaravelMikrotik\Exceptions\ConnectionException as MikrotikConnectionException;
use Easybdit\LaravelMikrotik\Exceptions\MalformedResponseException;
use Easybdit\LaravelMikrotik\Exceptions\RouterOsException;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException as HttpRequestException;
use Illuminate\Http\Client\Response;
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
 * hammering credentials that are simply wrong. This retry configuration
 * applies to get()/post() only -- see put()/patch()/delete()/postWrite()
 * below.
 *
 * Write methods (P12, foundation only -- nothing in this package calls
 * these publicly yet): put()/patch()/delete() map to RouterOS REST's
 * documented add/set/remove verbs (source: help.mikrotik.com "REST
 * API"). They deliberately never use the retry configuration above,
 * regardless of $retryTimes -- an ambiguous failure (timeout, dropped
 * connection) after a write has already reached RouterOS cannot be
 * safely retried without knowing whether it applied, and retrying a
 * "create" risks a duplicate resource. Write retry is not implemented
 * at all here; it would need to be a separate, explicit mechanism.
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

    /**
     * P24: fetches multiple read-only paths concurrently, via Laravel's
     * `Http::pool()` — measured directly against a real device
     * (RouterOS 7.10.2, RB3011UiAS): three sequential GETs
     * (`/system/resource`, `/system/health`, `/interface`) took
     * ~1000-1250ms total; the same three concurrently took as long as
     * the single slowest one (`/interface`, ~650-700ms) — roughly
     * halving total latency. Used internally by
     * RouterConnection::pollAll() (SnapshotRecorder's hot path); not
     * otherwise exposed as a generic public capability beyond that,
     * since this package makes no other multi-request read today.
     *
     * $requests is `array<string, array{path: string, query?: array}>`
     * keyed by a caller-chosen name; the result is
     * `array<string, array>` decoded bodies keyed the same way. Every
     * pooled response is mapped through the exact same exception logic
     * a single get() already uses (handleResponse() below) — a failure
     * on any one request throws immediately, the same all-or-nothing
     * contract get() already has. This fully supports the same opt-in
     * retry configuration get()/post() already have (P9) — each pooled
     * request is independently retried under the same conditions
     * (transient connection failure or a RouterOS-side 5xx), so
     * enabling retry does not silently stop applying to whatever this
     * method is used for.
     *
     * @param array<string, array{path: string, query?: array<string, scalar>}> $requests
     * @return array<string, array<string|int, mixed>>
     */
    public function getMany(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $paths = [];
        foreach ($requests as $name => $request) {
            $paths[$name] = $this->normalizePath($request['path']);
        }

        $responses = Http::pool(function (Pool $pool) use ($requests, $paths) {
            $pending = [];

            foreach ($requests as $name => $request) {
                $client = $this->applyRetry(
                    $pool->as($name)
                        ->withBasicAuth($this->username, $this->password)
                        ->withOptions(['verify' => $this->verifyTls])
                        ->timeout($this->timeoutSeconds)
                        ->acceptJson()
                );

                $pending[$name] = $client->get($this->url($paths[$name]), $request['query'] ?? []);
            }

            return $pending;
        });

        $results = [];

        foreach ($paths as $name => $path) {
            $result = $responses[$name];

            if ($result instanceof HttpConnectionException) {
                throw $this->connectionExceptionFor($result);
            }

            // Laravel's Http::pool() returns any exception a pooled
            // request raised in place of a Response, not only a
            // HttpConnectionException (confirmed directly: Http::fake()'s
            // closure-throw form surfaces here as the raw exception
            // object). Mirrors send()'s existing behavior exactly -- it
            // only ever catches HttpConnectionException itself, letting
            // anything else propagate unmodified -- rather than silently
            // passing a non-Response into handleResponse() below.
            if ($result instanceof \Throwable) {
                throw $result;
            }

            $results[$name] = $this->handleResponse($path, $result);
        }

        return $results;
    }

    public function post(string $path, array $body = []): array
    {
        $path = $this->normalizePath($path);

        return $this->send($path, fn () => $this->client()->post($this->url($path), $body));
    }

    public function put(string $path, array $body = []): array
    {
        $path = $this->normalizePath($path);

        return $this->send($path, fn () => $this->writeClient()->put($this->url($path), $body));
    }

    public function patch(string $path, array $body = []): array
    {
        $path = $this->normalizePath($path);

        return $this->send($path, fn () => $this->writeClient()->patch($this->url($path), $body));
    }

    public function delete(string $path): array
    {
        $path = $this->normalizePath($path);

        return $this->send($path, fn () => $this->writeClient()->delete($this->url($path)));
    }

    /** P21: a non-retrying POST write, for singleton "set" menus (see Transport::postWrite()'s docblock). */
    public function postWrite(string $path, array $body = []): array
    {
        $path = $this->normalizePath($path);

        return $this->send($path, fn () => $this->writeClient()->post($this->url($path), $body));
    }

    /** No retry -- used by get()/post() only. */
    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->applyRetry($this->baseClient());
    }

    /**
     * Applies this connection's opt-in retry configuration (P9) to an
     * already-built client, if $retryTimes > 0 — shared by client()
     * (single get()/post()) and getMany()'s per-pooled-request builder
     * (P24), so both retry under the exact same conditions.
     */
    private function applyRetry(\Illuminate\Http\Client\PendingRequest $client): \Illuminate\Http\Client\PendingRequest
    {
        if ($this->retryTimes <= 0) {
            return $client;
        }

        // throw:false is deliberate -- retry() must still hand back a
        // plain (possibly still-failing) Response after retries are
        // exhausted, so handleResponse()'s existing status-code handling
        // decides the final outcome exactly as it already does today;
        // this only adds retry attempts in front of that, unchanged
        // logic.
        return $client->retry(
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

    /** Never retried, unconditionally -- used by put()/patch()/delete() only. See this class's docblock. */
    private function writeClient(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->baseClient();
    }

    private function baseClient(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withBasicAuth($this->username, $this->password)
            ->withOptions(['verify' => $this->verifyTls])
            ->timeout($this->timeoutSeconds)
            ->acceptJson();
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
            throw $this->connectionExceptionFor($e);
        }

        return $this->handleResponse($path, $response);
    }

    /** Shared by send() and getMany() (P24) — both map a connection-level failure the same way. */
    private function connectionExceptionFor(HttpConnectionException $e): MikrotikConnectionException
    {
        if ($this->isTimeout($e)) {
            return MikrotikConnectionException::timedOut($this->hostLabel(), $this->timeoutSeconds);
        }

        return MikrotikConnectionException::unreachable($this->hostLabel(), $this->sanitizedPrevious($e));
    }

    /**
     * Turns an already-obtained Response into a decoded array, or
     * throws — shared by send() (a single request) and getMany() (P24,
     * each pooled response), so a pooled request is handled exactly the
     * same way a single one already is.
     */
    private function handleResponse(string $path, Response $response): array
    {
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

        // P13: a successful DELETE returns an empty body (confirmed:
        // help.mikrotik.com "REST API" -- "If the deletion has been
        // succeeded, the server responds with an empty response"), which
        // is not valid JSON on its own and must not be treated as
        // malformed. This is checked before decoding, not just for
        // delete() specifically, since the same empty-body-on-success
        // shape could apply to any verb RouterOS chooses to respond to
        // this way.
        if ($response->body() === '') {
            return [];
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
