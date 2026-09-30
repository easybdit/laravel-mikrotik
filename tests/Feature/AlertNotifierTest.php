<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Tests\Feature;

use Easybdit\LaravelMikrotik\Models\MikrotikAlert;
use Easybdit\LaravelMikrotik\Monitoring\AlertNotifier;
use Easybdit\LaravelMikrotik\Notifications\MikrotikAlertNotification;
use Easybdit\LaravelMikrotik\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

class AlertNotifierTest extends TestCase
{
    use RefreshDatabase;

    private function makeAlert(): MikrotikAlert
    {
        return MikrotikAlert::query()->create([
            'connection'   => 'default',
            'metric'       => 'resource.cpu_load',
            'operator'     => '>',
            'threshold'    => 90,
            'value'        => 95,
            'status'       => MikrotikAlert::STATUS_TRIGGERED,
            'message'      => 'High CPU load',
            'triggered_at' => now(),
        ]);
    }

    public function test_notify_is_a_noop_when_no_channel_is_configured(): void
    {
        Notification::fake();

        $this->app->make(AlertNotifier::class)->notify($this->makeAlert());

        Notification::assertNothingSent();
    }

    public function test_notify_sends_mail_only_when_mail_is_configured(): void
    {
        config()->set('mikrotik.notifications.mail', ['enabled' => true, 'to' => 'ops@example.test']);
        Notification::fake();

        $this->app->make(AlertNotifier::class)->notify($this->makeAlert());

        // via() always lists both channels (see MikrotikAlertNotification)
        // -- it's each channel's own send() that no-ops without a route,
        // so what AlertNotifier actually controls, and what's testable
        // through the fake, is which routes got registered.
        Notification::assertSentOnDemand(
            MikrotikAlertNotification::class,
            function (MikrotikAlertNotification $notification, array $channels, $notifiable) {
                return $notifiable->routeNotificationFor('mail') === 'ops@example.test'
                    && $notifiable->routeNotificationFor('webhook') === null;
            }
        );
    }

    public function test_notify_sends_webhook_only_when_webhook_is_configured(): void
    {
        config()->set('mikrotik.notifications.webhook', ['enabled' => true, 'url' => 'https://hooks.test/mikrotik']);
        Notification::fake();

        $this->app->make(AlertNotifier::class)->notify($this->makeAlert());

        Notification::assertSentOnDemand(
            MikrotikAlertNotification::class,
            function (MikrotikAlertNotification $notification, array $channels, $notifiable) {
                return $notifiable->routeNotificationFor('webhook') === 'https://hooks.test/mikrotik'
                    && $notifiable->routeNotificationFor('mail') === null;
            }
        );
    }

    public function test_notify_sends_both_when_both_are_configured(): void
    {
        config()->set('mikrotik.notifications.mail', ['enabled' => true, 'to' => 'ops@example.test']);
        config()->set('mikrotik.notifications.webhook', ['enabled' => true, 'url' => 'https://hooks.test/mikrotik']);
        Notification::fake();

        $this->app->make(AlertNotifier::class)->notify($this->makeAlert());

        Notification::assertSentOnDemandTimes(MikrotikAlertNotification::class, 1);
    }

    public function test_webhook_channel_actually_posts_the_alert_payload(): void
    {
        config()->set('mikrotik.notifications.webhook', ['enabled' => true, 'url' => 'https://hooks.test/mikrotik']);
        Http::fake(['hooks.test/*' => Http::response(['ok' => true], 200)]);

        $alert = $this->makeAlert();
        $this->app->make(AlertNotifier::class)->notify($alert);

        Http::assertSent(function ($request) use ($alert) {
            return $request->url() === 'https://hooks.test/mikrotik'
                && $request['connection'] === 'default'
                && $request['status'] === MikrotikAlert::STATUS_TRIGGERED
                && $request['value'] === 95.0
                && $request['threshold'] === 90.0;
        });
    }

    public function test_disabled_channel_with_a_value_still_set_is_not_used(): void
    {
        // enabled=false must win even if 'to'/'url' happen to be set --
        // e.g. left over from a previously-enabled config.
        config()->set('mikrotik.notifications.mail', ['enabled' => false, 'to' => 'ops@example.test']);
        config()->set('mikrotik.notifications.webhook', ['enabled' => false, 'url' => 'https://hooks.test/mikrotik']);
        Notification::fake();

        $this->app->make(AlertNotifier::class)->notify($this->makeAlert());

        Notification::assertNothingSent();
    }
}
