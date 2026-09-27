<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Facades;

use Easybdit\LaravelMikrotik\Connection\RouterConnection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static RouterConnection connection(?string $name = null)
 *
 * @see \Easybdit\LaravelMikrotik\Connection\ConnectionManager
 */
class Mikrotik extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'mikrotik';
    }
}
