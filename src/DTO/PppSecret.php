<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of RouterOS's `/ppp/secret` menu (P17). Field set confirmed
 * directly against a real device (RouterOS 7.10.2, RB3011UiAS): `.id`,
 * `name`, `service`, `caller-id`, `profile`, `remote-address`,
 * `disabled` were all observed on a live secret.
 *
 * **Deliberate exception to this package's usual "$raw is the untouched
 * response" contract**: RouterOS's REST API confirmed (directly against
 * a real device) returns a PPP secret's `password` in plain text on
 * every GET/PUT/PATCH response. Every other DTO in this package
 * preserves $raw exactly as received; this one does not — the
 * `password` key is stripped from $raw before it ever reaches this
 * object, and this DTO has no `$password` property at all, so a secret
 * cannot flow into a log line, an exception, a `toArray()`/`json_encode()`
 * dump, or anywhere else in an application simply by this package
 * handling it. If your application genuinely needs the password value,
 * read it directly from RouterOS's own response outside this package —
 * this package will not carry it for you.
 *
 * $localAddress is deliberately not typed here: it was not present on
 * the one real secret this package could observe (a PPPoE secret, which
 * does not use it) — see PppSecretResource's docblock.
 */
final class PppSecret
{
    /** RouterOS fields never allowed to reach $raw or a typed property. */
    public const REDACTED_FIELDS = ['password'];

    public function __construct(
        public readonly ?string $id,
        public readonly ?string $name,
        public readonly ?string $service,
        public readonly ?string $callerId,
        public readonly ?string $profile,
        public readonly ?string $remoteAddress,
        public readonly ?bool $disabled,
        public readonly ?string $comment,
        public readonly array $raw,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'service'        => $this->service,
            'caller_id'      => $this->callerId,
            'profile'        => $this->profile,
            'remote_address' => $this->remoteAddress,
            'disabled'       => $this->disabled,
            'comment'        => $this->comment,
        ];
    }
}
