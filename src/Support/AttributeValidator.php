<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Support;

use Easybdit\LaravelMikrotik\Exceptions\InvalidResourceException;

/**
 * Shared pre-flight check for the attributes passed to a typed write
 * resource's add()/update() (P25). It does not allow-list property
 * names (the full RouterOS schema per menu is not known), it only
 * guarantees the payload is well-formed: plain property names, scalar
 * values, and no RouterOS REST control keys (".id", ".query",
 * ".proplist", ...) smuggled into a write body.
 */
final class AttributeValidator
{
    private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_\-.]*$/';

    /** @param array<array-key, mixed> $attributes */
    public static function assertSafe(array $attributes, string $resource = 'resource'): void
    {
        $resource = class_basename($resource);

        foreach ($attributes as $key => $value) {
            if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw InvalidResourceException::invalidAttribute(
                    $resource,
                    'attribute names must be plain RouterOS property names (letters, digits, "_", "-", "."), '
                        . 'and must not start with "." or be numeric.'
                );
            }

            if (is_string($value) && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
                throw InvalidResourceException::invalidAttribute(
                    $resource,
                    "attribute [{$key}] contains a control character."
                );
            }

            if (!is_scalar($value)) {
                throw InvalidResourceException::invalidAttribute(
                    $resource,
                    "attribute [{$key}] must be a string, number, or boolean."
                );
            }
        }
    }
}
