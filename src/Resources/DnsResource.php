<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\DnsSettings;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;

/**
 * Typed, read+write access to RouterOS's `/ip/dns` menu (P21) —
 * architecturally different from every other typed write resource in
 * this package: `/ip/dns` is a **singleton settings object**, not a
 * list. There is no `.id`, no `list()`/`find()`/`add()`/`remove()`, and
 * no `enable()`/`disable()` — only `get()` and `update()`.
 *
 * MikroTik's REST documentation does not show a PATCH example for a
 * singleton menu (only for a list menu with an `.id` in the path, e.g.
 * `/ip/address/*3`). `GET /rest/ip/dns` is confirmed directly against a
 * real device (RouterOS 7.10.2, RB3011UiAS) to return a single JSON
 * object, not a list — see DnsSettings's docblock for the confirmed
 * field set.
 *
 * `update()` does **not** use PATCH. This package's first hypothesis for
 * *any* singleton menu — `PATCH /rest/<path>` with no identifier — was
 * tested directly against a real device on `/system/identity` (P21) and
 * confirmed **wrong**: RouterOS rejects it with `"missing or invalid
 * resource identifier"` (HTTP 400). The working form confirmed there is
 * `POST /rest/<path>/set` — RouterOS REST's documented "run a console
 * command via POST" mechanism, applied to the menu's own `set` command,
 * not a PATCH-a-list-item convention at all — see
 * Contracts\Transport::postWrite()'s docblock. `update()` here uses that
 * same confirmed convention (`POST /rest/ip/dns/set`), **not**
 * independently re-tested against a real device for `/ip/dns`
 * specifically: this package deliberately did not write-test `/ip/dns`
 * against the real device it was verified against, since changing DNS
 * servers is a functionally significant change to a live router, not a
 * harmless one — see the README's real-device testing notes for this
 * phase before relying on `update()` in production.
 */
final class DnsResource
{
    private const PATH = '/ip/dns';

    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    public function get(): DnsSettings
    {
        return $this->normalizer->normalizeDnsSettings($this->transport->get(self::PATH));
    }

    /**
     * @param array<string, scalar> $attributes e.g. ['servers' => '8.8.8.8,1.1.1.1'].
     *
     * Confirmed directly against a real device (P21, via the identical
     * `/system/identity/set` case): RouterOS's `set` command returns an
     * **empty body** on success — unlike every other write in this
     * package, there is no updated record in the POST response to
     * normalize. This method therefore issues a follow-up get() after
     * the write succeeds and returns that, rather than guessing at an
     * empty response.
     */
    public function update(array $attributes): DnsSettings
    {
        $this->transport->postWrite(self::PATH . '/set', $this->wireAttributes($attributes));

        return $this->get();
    }

    /** @param array<string, scalar> $attributes @return array<string, scalar> */
    private function wireAttributes(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (is_bool($value)) {
                $attributes[$key] = $value ? 'true' : 'false';
            }
        }

        return $attributes;
    }
}
