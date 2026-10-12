<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Support;

/**
 * Name-based removal of credential-bearing RouterOS fields from raw
 * rows (P25). Best effort by design: it covers well-known RouterOS
 * property names, not every secret a future RouterOS release might add.
 * Typed DTOs (PppSecret, RouterUser) apply it unconditionally; the
 * generic Connection\Menu applies it by default.
 */
final class SecretRedactor
{
    public const FIELDS = [
        'password',
        'secret',
        'ipsec-secret',
        'psk',
        'preshared-key',
        'wpa-pre-shared-key',
        'wpa2-pre-shared-key',
        'private-key',
        'passphrase',
        'authentication-password',
        'encryption-password',
    ];

    public static function isSensitive(string $field): bool
    {
        return in_array(strtolower($field), self::FIELDS, true);
    }

    /**
     * @param array<string|int, mixed> $row
     * @return array<string|int, mixed>
     */
    public static function redact(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                unset($row[$key]);

                continue;
            }

            if (is_array($value)) {
                $row[$key] = self::redact($value);
            }
        }

        return $row;
    }

    /**
     * Replaces every occurrence of $secret inside string values (used to
     * keep this connection's own password out of RouterOS error text).
     *
     * @param array<string|int, mixed> $body
     * @return array<string|int, mixed>
     */
    public static function scrubValue(array $body, string $secret): array
    {
        if ($secret === '') {
            return $body;
        }

        foreach ($body as $key => $value) {
            if (is_string($value)) {
                $body[$key] = str_replace($secret, '***', $value);
            } elseif (is_array($value)) {
                $body[$key] = self::scrubValue($value, $secret);
            }
        }

        return $body;
    }
}
