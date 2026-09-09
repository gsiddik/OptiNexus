<?php

namespace App\Services\Notification;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\NotificationRule;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Integration\SsrfSafeHttpClient;
use App\Jobs\SendNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class NotificationDispatchService
{
    public function __construct(
        private readonly NotificationRecipientResolver $resolver,
        private readonly NotificationTemplateRenderer $renderer,
        private readonly SsrfSafeHttpClient $http,
    ) {}

    /**
     * @return Notification[]
     */
    public function dispatchForRule(NotificationRule $rule, array $context, ?string $correlationId = null, ?string $causationId = null): array
    {
        $template = $rule->template;
        if (! $rule->isActive() || ! $template || ! $template->isActive()) {
            return [];
        }

        $recipients = $this->resolver->resolve($rule, $context);

        return array_map(
            fn (string $userId) => $this->create($template, $userId, $context, $rule, $correlationId, $causationId, $rule->tenant_id, $rule->application_id, null),
            $recipients,
        );
    }

    /**
     * @param  string[]  $recipientUserIds
     * @return Notification[]
     */
    public function sendDirect(NotificationTemplate $template, array $recipientUserIds, array $context, ?string $tenantId, ?string $applicationId, ?string $correlationId, ?User $actor): array
    {
        return array_map(
            fn (string $userId) => $this->create($template, $userId, $context, null, $correlationId, null, $tenantId, $applicationId, $actor),
            $recipientUserIds,
        );
    }

    private function create(NotificationTemplate $template, string $userId, array $context, ?NotificationRule $rule, ?string $correlationId, ?string $causationId, ?string $tenantId, ?string $applicationId, ?User $actor): Notification
    {
        $context['recipient'] = ['user_id' => $userId];

        $notification = Notification::create([
            'tenant_id' => $tenantId,
            'application_id' => $applicationId,
            'notification_rule_id' => $rule?->id,
            'notification_template_id' => $template->id,
            'recipient_user_id' => $userId,
            'channel' => $template->channel,
            'subject' => $template->subject_template ? $this->renderer->render($template->subject_template, $context) : null,
            'body' => $this->renderer->render($template->body_template, $context),
            'status' => Notification::STATUS_QUEUED,
            'correlation_id' => $correlationId,
            'causation_id' => $causationId,
            'created_by' => $actor?->id,
        ]);

        SendNotification::dispatch($notification->id);

        return $notification;
    }

    /**
     * Performs one delivery attempt. Throws on failure so the caller (the
     * queued job) can decide whether to let the queue's own retry/backoff
     * run or mark the notification permanently FAILED.
     */
    public function attemptDelivery(Notification $notification): void
    {
        if ($notification->recipient_user_id && $this->recipientOptedOut($notification)) {
            $this->markCancelled($notification, 'Recipient has opted out of this channel.');

            throw new \RuntimeException('NOTIFICATION_RECIPIENT_DENIED: recipient opted out.');
        }

        $notification->update(['status' => Notification::STATUS_PROCESSING]);

        match ($notification->channel) {
            NotificationTemplate::CHANNEL_IN_APP => null, // the persisted row itself is the in-app notification
            NotificationTemplate::CHANNEL_EMAIL => $this->sendEmail($notification),
            NotificationTemplate::CHANNEL_WEBHOOK => $this->sendWebhook($notification),
            default => throw new \RuntimeException("Unsupported notification channel [{$notification->channel}]."),
        };

        $this->markSent($notification);
    }

    public function markSent(Notification $notification): void
    {
        $notification->update(['status' => Notification::STATUS_SENT, 'sent_at' => now()]);
        $this->recordDeliveryAttempt($notification, 'SUCCESS');
    }

    public function markFailed(Notification $notification, string $errorMessage): void
    {
        $sanitized = Str::limit(preg_replace('/[A-Za-z0-9+\/=]{32,}/', '[redacted]', $errorMessage), 500);
        $notification->update([
            'status' => Notification::STATUS_FAILED,
            'last_error_code' => 'NOTIFICATION_DELIVERY_FAILED',
            'last_error_message' => $sanitized,
        ]);
        $this->recordDeliveryAttempt($notification, 'FAILED', $sanitized);
    }

    public function markCancelled(Notification $notification, string $reason): void
    {
        $notification->update(['status' => Notification::STATUS_CANCELLED, 'last_error_message' => $reason]);
        $this->recordDeliveryAttempt($notification, 'FAILED', $reason);
    }

    public function retry(Notification $notification): ?string
    {
        if ($notification->status !== Notification::STATUS_FAILED) {
            return 'NOTIFICATION_NOT_RETRYABLE';
        }

        $notification->update(['status' => Notification::STATUS_QUEUED, 'next_retry_at' => null]);
        SendNotification::dispatch($notification->id);

        return null;
    }

    public function cancel(Notification $notification): ?string
    {
        if (! $notification->isOpen()) {
            return 'NOTIFICATION_NOT_CANCELLABLE';
        }

        $notification->update(['status' => Notification::STATUS_CANCELLED]);

        return null;
    }

    private function recipientOptedOut(Notification $notification): bool
    {
        $pref = NotificationPreference::where('user_id', $notification->recipient_user_id)
            ->where('channel', $notification->channel)
            ->first();

        return $pref && ! $pref->enabled;
    }

    private function sendEmail(Notification $notification): void
    {
        $email = $notification->recipient?->email;
        if (! $email) {
            throw new \RuntimeException('Recipient has no email address on file.');
        }

        Mail::raw($notification->body, function ($message) use ($notification, $email) {
            $message->to($email)->subject($notification->subject ?? 'Notification');
        });
    }

    private function sendWebhook(Notification $notification): void
    {
        if (! $notification->channel_target) {
            throw new \RuntimeException('No webhook target configured for this notification.');
        }

        $this->http->send('POST', $notification->channel_target, [
            'json' => [
                'notification_id' => $notification->id,
                'correlation_id' => $notification->correlation_id,
                'subject' => $notification->subject,
                'body' => $notification->body,
            ],
        ]);
    }

    private function recordDeliveryAttempt(Notification $notification, string $status, ?string $error = null): void
    {
        $notification->deliveries()->create([
            'attempt_number' => $notification->attempt_count + 1,
            'status' => $status,
            'error_message' => $error,
        ]);
        $notification->increment('attempt_count');
    }
}
