<?php

// config for easybdit/laravel-mikrotik

return [

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | The named connection (see "connections" below) used when a connection
    | name is not explicitly requested, e.g. Mikrotik::connection() without
    | an argument.
    |
    */

    'default' => env('MIKROTIK_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Each entry describes one MikroTik router reachable over the RouterOS
    | REST API. Add one entry per router you want to monitor, e.g.
    | "office-main", "branch-01". P1 only supports HTTPS (the "rest"
    | transport); RouterOS must have the www-ssl service enabled
    | (RouterOS >= v7.1beta4).
    |
    | verify_tls should stay true in production. Only disable it for a
    | router using a self-signed certificate you have not imported into
    | a trusted CA store, and only if you understand the risk.
    |
    */

    'connections' => [

        'default' => [
            'transport'   => 'rest',
            'host'        => env('MIKROTIK_HOST'),
            'port'        => env('MIKROTIK_PORT', 443),
            'username'    => env('MIKROTIK_USERNAME', 'admin'),
            'password'    => env('MIKROTIK_PASSWORD', ''),
            'verify_tls'  => env('MIKROTIK_VERIFY_TLS', true),
            'timeout'     => env('MIKROTIK_TIMEOUT', 10),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Optional delivery of P5 alert (triggered/resolved) notifications via
    | Monitoring\AlertNotifier. Both channels are disabled by default: an
    | application that sets neither *_ENABLED variable is unaffected, and
    | AlertNotifier::notify() is a silent no-op with nothing configured.
    |
    | 'mail.to' accepts a single address or a comma-separated list.
    |
    */

    'notifications' => [

        'mail' => [
            'enabled' => env('MIKROTIK_NOTIFY_MAIL_ENABLED', false),
            'to'      => env('MIKROTIK_NOTIFY_MAIL_TO'),
        ],

        'webhook' => [
            'enabled' => env('MIKROTIK_NOTIFY_WEBHOOK_ENABLED', false),
            'url'     => env('MIKROTIK_NOTIFY_WEBHOOK_URL'),
        ],

    ],

];
