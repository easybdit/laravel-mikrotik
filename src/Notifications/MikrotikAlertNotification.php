<?php

declare(strict_types=1);

namespace Easybdit\LaravelMikrotik\Notifications;

use Easybdit\LaravelMikrotik\Models\MikrotikAlert;
use Easybdit\LaravelMikrotik\Notifications\Channels\WebhookChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "This MikrotikAlert (P5) is triggered, or has just been resolved" --
 * sent via Monitoring\AlertNotifier, never automatically by RuleEvaluator
 * itself (P5's evaluate() has no knowledge of notifications).
 *
 * Delivered through Laravel's own on-demand/anonymous notifiable
 * (`Notification::route('mail', ...)->route('webhook', ...)->notify(...)`)
 * rather than requiring a host application's own Notifiable model -- this
 * package has no concept of a "user" to attach a notification to.
 *
 * via() always offers both channels; each channel's own send() no-ops
 * when the anonymous notifiable has no route registered for it (the same
 * pattern Laravel's own MailChannel uses), so listing both here is safe
 * even when only one is actually configured.
 */
class MikrotikAlertNotification extends Notification
{
    public function __construct(public readonly MikrotikAlert $alert)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', WebhookChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $resolved = $this->alert->status === MikrotikAlert::STATUS_RESOLVED;

        $mail = (new MailMessage())
            ->subject(($resolved ? '[Resolved] ' : '[Triggered] ') . "MikroTik alert — {$this->alert->connection}")
            ->line($resolved ? 'A previously triggered alert has been resolved.' : 'A monitoring rule has been triggered.')
            ->line("Connection: {$this->alert->connection}")
            ->line("Metric: {$this->alert->metric}")
            ->line("Condition: {$this->alert->operator} {$this->alert->threshold}")
            ->line("Observed value: {$this->alert->value}");

        if ($this->alert->message !== null && $this->alert->message !== '') {
            $mail->line("Rule: {$this->alert->message}");
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    public function toWebhook(object $notifiable): array
    {
        return [
            'id'           => $this->alert->id,
            'status'       => $this->alert->status,
            'connection'   => $this->alert->connection,
            'metric'       => $this->alert->metric,
            'operator'     => $this->alert->operator,
            'threshold'    => $this->alert->threshold,
            'value'        => $this->alert->value,
            'message'      => $this->alert->message,
            'triggered_at' => $this->alert->triggered_at?->toIso8601String(),
            'resolved_at'  => $this->alert->resolved_at?->toIso8601String(),
        ];
    }
}
