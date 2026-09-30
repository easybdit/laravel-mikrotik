<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Exceptions;

/**
 * Thrown when a menu path or item identifier given to Connection\Menu
 * (RouterConnection::menu()) fails this package's own safety validation
 * — an attempted path traversal (".."), a protocol/absolute-URL-like
 * value, or a character outside what a RouterOS menu path or identifier
 * can legitimately contain. Never thrown for anything RouterOS itself
 * said or did.
 */
class InvalidMenuPathException extends MikrotikException
{
    public static function invalidPath(string $path): self
    {
        return new self(
            "MikroTik menu path [{$path}] is not valid. Use a plain RouterOS menu path made of letters, "
                . "digits, '_', '-', and '/' as a segment separator (e.g. \"ip/address\") — no leading/trailing "
                . 'slash, ".." , or URL-like syntax.'
        );
    }

    public static function invalidIdentifier(string $id): self
    {
        return new self(
            "MikroTik item identifier [{$id}] is not valid. Use a RouterOS \".id\" (e.g. \"*1\") or item name "
                . "made of letters, digits, '_', '-', '.', and '*' only."
        );
    }
}
