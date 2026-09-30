<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Delivers a notification by POSTing its toWebhook() array as JSON to the
 * notifiable's "webhook" route -- the same on-demand-notifiable pattern
 * Laravel's own MailChannel uses for "mail", just for an arbitrary URL
 * instead of an email address.
 *
 * A notification with no toWebhook() method, or a notifiable with no
 * "webhook" route registered, is silently skipped -- not an error, the
 * same way an on-demand notifiable with no "mail" route silently skips
 * Laravel's own MailChannel.
 */
final class WebhookChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toWebhook')) {
            return;
        }

        $url = $notifiable->routeNotificationFor('webhook', $notification);

        if (!is_string($url) || $url === '') {
            return;
        }

        Http::post($url, $notification->toWebhook($notifiable));
    }
}
