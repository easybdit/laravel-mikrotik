<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Resources;

use Easybdit\LaravelMikrotik\Contracts\Transport;
use Easybdit\LaravelMikrotik\DTO\SystemIdentity;
use Easybdit\LaravelMikrotik\Support\ResponseNormalizer;

/**
 * Typed, read+write access to RouterOS's `/system/identity` menu (P21)
 * — a singleton settings object (no `.id`), the same shape as
 * DnsResource: only `get()`/`update()`, no `list()`/`find()`/`add()`/
 * `remove()`/`enable()`/`disable()`. Confirmed directly against a real
 * device (RouterOS 7.10.2, RB3011UiAS) that this menu has exactly one
 * field: `name`.
 *
 * **This is the resource this package used to discover the confirmed
 * singleton-menu write convention** (P21 real-device testing): a naive
 * `PATCH /rest/system/identity` (no identifier) was tried first and
 * rejected by RouterOS with `"missing or invalid resource identifier"`
 * (HTTP 400) — no change was made. `POST /rest/system/identity/set`
 * (RouterOS's own `set` console command, run via REST's documented
 * POST-a-command mechanism) was then confirmed to work, but returns an
 * **empty body** on success, not the updated record — see
 * Contracts\Transport::postWrite()'s docblock and update()'s own
 * docblock below. A full rename → verify → restore → verify cycle was
 * completed and confirmed against the real device with this corrected
 * implementation.
 */
final class SystemIdentityResource
{
    private const PATH = '/system/identity';

    public function __construct(
        private readonly Transport $transport,
        private readonly ResponseNormalizer $normalizer,
    ) {
    }

    public function get(): SystemIdentity
    {
        return $this->normalizer->normalizeSystemIdentity($this->transport->get(self::PATH));
    }

    /**
     * Confirmed directly against a real device: RouterOS's `set`
     * command returns an empty body on success, so this issues a
     * follow-up get() after the write succeeds and returns that.
     */
    public function update(string $name): SystemIdentity
    {
        $this->transport->postWrite(self::PATH . '/set', ['name' => $name]);

        return $this->get();
    }
}
