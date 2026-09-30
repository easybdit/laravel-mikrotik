<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Monitoring;

use Easybdit\LaravelMikrotik\Models\MikrotikAlert;
use Easybdit\LaravelMikrotik\Notifications\MikrotikAlertNotification;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Notification;

/**
 * Optional delivery of a MikrotikAlert (P5) via mail and/or webhook,
 * gated entirely by config('mikrotik.notifications.*') -- see
 * config/mikrotik.php. With neither channel enabled (the default),
 * notify() is a silent no-op: nothing is sent, and Laravel's
 * Notification facade is never touched.
 *
 * This is deliberately synchronous (no ShouldQueue) -- an application
 * that wants queued delivery can queue its own call to notify(), or wrap
 * MikrotikAlertNotification in its own queued notification; this package
 * does not add queue infrastructure that isn't needed by default.
 */
final class AlertNotifier
{
    public function __construct(private readonly ConfigRepository $config)
    {
    }

    public function notify(MikrotikAlert $alert): void
    {
        $routes = $this->routes();

        if ($routes === []) {
            return;
        }

        $notifiable = null;

        foreach ($routes as $channel => $route) {
            $notifiable = $notifiable === null
                ? Notification::route($channel, $route)
                : $notifiable->route($channel, $route);
        }

        $notifiable->notify(new MikrotikAlertNotification($alert));
    }

    /** @return array<string, string> Channel name => route, for every enabled+configured channel. */
    private function routes(): array
    {
        $routes = [];

        $mail = (array) $this->config->get('mikrotik.notifications.mail', []);
        if (($mail['enabled'] ?? false) && !empty($mail['to'])) {
            $routes['mail'] = (string) $mail['to'];
        }

        $webhook = (array) $this->config->get('mikrotik.notifications.webhook', []);
        if (($webhook['enabled'] ?? false) && !empty($webhook['url'])) {
            $routes['webhook'] = (string) $webhook['url'];
        }

        return $routes;
    }
}
