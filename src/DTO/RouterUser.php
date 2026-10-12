<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\DTO;

/**
 * One row of RouterOS's `/user` menu (P22). Field set taken from
 * RouterOS's documented `/user` properties (name, group, address,
 * last-logged-in, disabled, comment); not exercised against a real
 * device, since changing router user accounts is not a harmless test.
 *
 * Like PppSecret, this DTO deliberately has no password property and
 * credential fields (see Support\SecretRedactor) are stripped from
 * $raw before it reaches this object.
 */
final class RouterUser
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $name,
        public readonly ?string $group,
        public readonly ?string $address,
        public readonly ?string $lastLoggedIn,
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
            'group'          => $this->group,
            'address'        => $this->address,
            'last_logged_in' => $this->lastLoggedIn,
            'disabled'       => $this->disabled,
            'comment'        => $this->comment,
        ];
    }
}
