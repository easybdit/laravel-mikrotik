<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Exceptions;

/**
 * Thrown by Connection\Menu::query() (P23) when a query word or
 * .proplist field fails this package's own validation, before any
 * request is sent. Never thrown for anything RouterOS itself said.
 */
class InvalidQueryException extends MikrotikException
{
    public static function unsupportedWord(string $word): self
    {
        return new self(
            "MikroTik query word [{$word}] is not supported. Use a \"field=value\" equality condition or one of "
                . 'the documented stack operators "#|" (OR) / "#|!" (OR then NOT).'
        );
    }

    public static function invalidProplistField(string $field): self
    {
        return new self("MikroTik .proplist field [{$field}] is not valid. Use a plain RouterOS property name.");
    }

    public static function sensitiveField(string $field): self
    {
        return new self(
            "MikroTik field [{$field}] is a sensitive field and cannot be queried or listed through menu() "
                . 'unless Menu::withSecrets() was called.'
        );
    }
}
