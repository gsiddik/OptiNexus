<?php

namespace App\Jobs;

use App\Models\EventDelivery;
use App\Models\EventSubscription;
use App\Services\Event\EventSubscriptionService;
use App\Services\Event\WebhookSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sends one event to one subscribed application as a signed webhook (docs/integration/INTEGRATION_GUIDE.md §4b).
 *
 * The body is the event envelope; the signature covers delivery id, timestamp and body, and is computed afresh on every
 * attempt. Redirects are never followed. 2xx ends it; 4xx other than 408/429 means the application refused it, so the
 * delivery FAILS at once; anything else is retried with backoff and, after the last attempt, stays FAILED (dead letter) for
 * an operator to retry or discard. Whether the application may still receive the event is checked before every attempt.
 */
class DeliverWebhook implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public array $backoff;

    public function __construct(public string $deliveryId)
    {
        $this->tries = (int) config('event_broker.tries');
        $this->backoff = (array) config('event_broker.backoff');
    }

    public function handle(EventSubscriptionService $subscriptions, WebhookSigner $signer): void
    {
        $delivery = EventDelivery::query()->with('event.sourceApplication')->find($this->deliveryId);
        if (! $delivery || $delivery->status !== EventDelivery::STATUS_PENDING || $delivery->consumer_type !== EventDelivery::CONSUMER_WEBHOOK) {
            return;
        }

        $subscription = EventSubscription::query()->with('application')->find($delivery->consumer_reference);
        $event = $delivery->event;
        if (! $event || ! $subscription || ! $subscriptions->mayReceive($subscription, $event)) {
            $delivery->update(['status' => EventDelivery::STATUS_DISCARDED, 'last_error_code' => 'SUBSCRIPTION_NOT_ELIGIBLE', 'last_error_message' => 'The subscription is disabled or no longer covers this tenant.']);

            return;
        }

        $body = json_encode([
            'event_id' => $event->id,
            'event_type' => $event->event_key,
            'schema_version' => $event->event_version,
            'source_system' => $event->sourceApplication?->application_code ?? 'optinexus',
            'tenant_id' => $event->tenant_id,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'correlation_id' => $event->correlation_id,
            'causation_id' => $event->causation_id,
            'data' => $event->data ?? (object) [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $attempt = $delivery->attempt_count + 1;
        $delivery->update(['attempt_count' => $attempt]);

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders($signer->headers($delivery->id, $event->id, (string) $subscription->signing_secret, $body))
                ->withOptions(['allow_redirects' => false])
                ->timeout((int) config('event_broker.timeout_seconds'))
                ->post($subscription->endpoint_url);
        } catch (ConnectionException $e) {
            $this->retryLater($delivery, $attempt, 'WEBHOOK_UNREACHABLE', $e->getMessage());

            throw $e;
        }

        $status = $response->status();
        if ($response->successful()) {
            $delivery->update(['status' => EventDelivery::STATUS_DELIVERED, 'delivered_at' => now(), 'last_error_code' => null, 'last_error_message' => null, 'next_retry_at' => null]);

            return;
        }

        if ($status >= 400 && $status < 500 && ! in_array($status, [408, 429], true)) {
            $delivery->update(['status' => EventDelivery::STATUS_FAILED, 'last_error_code' => "HTTP_{$status}", 'last_error_message' => Str::limit($response->body(), 500)]);

            return;
        }

        $this->retryLater($delivery, $attempt, "HTTP_{$status}", $response->body());

        throw new RuntimeException("Webhook endpoint answered HTTP {$status}.");
    }

    public function failed(Throwable $e): void
    {
        EventDelivery::query()->whereKey($this->deliveryId)->where('status', EventDelivery::STATUS_PENDING)
            ->update(['status' => EventDelivery::STATUS_FAILED, 'next_retry_at' => null, 'last_error_message' => Str::limit($e->getMessage(), 500)]);
    }

    private function retryLater(EventDelivery $delivery, int $attempt, string $code, string $message): void
    {
        $wait = $this->backoff[$attempt - 1] ?? end($this->backoff);
        $delivery->update(['last_error_code' => $code, 'last_error_message' => Str::limit($message, 500), 'next_retry_at' => now()->addSeconds((int) $wait)]);
    }
}
