<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Exceptions;

/**
 * Thrown for a MikrotikRule that is itself malformed — an operator this
 * package does not recognise, or a metric path that does not start with
 * a known snapshot section ("resource.", "health.", "interfaces.") —
 * never for anything the router itself returned. Raised eagerly when the
 * rule is saved (see MikrotikRule::booted()), not deferred to the next
 * time a snapshot happens to be evaluated against it.
 */
class InvalidRuleException extends MikrotikException
{
    public static function unsupportedOperator(string $operator): self
    {
        return new self(
            "MikroTik rule operator [{$operator}] is not supported. Use one of: '>', '>=', '<', '<=', '==', '!='."
        );
    }

    public static function unsupportedMetric(string $metric): self
    {
        return new self(
            "MikroTik rule metric [{$metric}] is not supported. It must start with 'resource.', 'health.', or 'interfaces.' (e.g. \"resource.cpu_load\", \"health.cpu-temperature.value\", \"interfaces.ether1.rx_error\")."
        );
    }
}
