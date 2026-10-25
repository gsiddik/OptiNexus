<?php

namespace App\Services\Event;

use App\Jobs\DeliverEventToConsumer;
use App\Jobs\DeliverWebhook;
use App\Models\Event;
use App\Models\EventCatalogEntry;
use App\Models\EventDelivery;
use App\Models\NotificationRule;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\Workflow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Event ingestion: validates the producer's event_key against the Event
 * Catalog, checks payload shape, and writes the immutable Event row
 * idempotently (same event_id twice -> the same row, never a duplicate
 * fact). Only a genuinely NEW event fans out to consumers (a matching
 * ACTIVE event-triggered Workflow, a matching ACTIVE NotificationRule, or a
 * subscribed application through the event broker)
 * - an idempotent replay never re-fires side effects. Each fan-out gets
 * its own EventDelivery row (outbox-style) so one consumer's failure
 * never blocks another's delivery.
 */
class EventDispatchService
{
    public function __construct(
        private readonly PayloadSchemaValidator $schemaValidator,
        private readonly EventSubscriptionService $subscriptions,
    ) {}

    /**
     * @return array{0: ?Event, 1: ?string, 2: array} [event, errorCode, errorDetails]
     */
    public function ingest(array $envelope, ServiceAccount $producer): array
    {
        $catalogEntry = EventCatalogEntry::query()->where('event_key', $envelope['event_key'])->first();

        if (! $catalogEntry) {
            return [null, 'EVENT_INVALID', ['reason' => 'Unknown event_key. Register it in the Event Catalog first.']];
        }

        // Before the status check, so a producer learns nothing about keys it does not own.
        if (! $this->producerOwnsKey($producer, $catalogEntry)) {
            return [null, 'EVENT_SOURCE_DENIED', ['reason' => 'This event_key is not registered to the sending application.']];
        }

        if (! $catalogEntry->isActive()) {
            return [null, 'EVENT_INVALID', ['reason' => 'This event_key is not ACTIVE in the Event Catalog.']];
        }

        $data = $envelope['data'] ?? [];
        $schemaErrors = $this->schemaValidator->validate($data, $catalogEntry->payload_schema);
        if ($schemaErrors) {
            return [null, 'EVENT_SCHEMA_INVALID', ['errors' => $schemaErrors]];
        }

        // Idempotent per producing application: the same producer id twice is one event; another application's id is its own.
        $producerEventId = $envelope['event_id'] ?? (string) Str::uuid();

        $existing = $this->findByProducerId($producer, $producerEventId);
        if ($existing) {
            // A genuine idempotent retry (same id, same fact) replays the
            // original record; the same id reused for a *different* fact is
            // a real conflict, not a safe-to-ignore duplicate.
            if ($existing->event_key !== $envelope['event_key'] || $this->canonical($existing->data) !== $this->canonical($data)) {
                return [null, 'EVENT_DUPLICATE', ['reason' => 'event_id already used for a different event.']];
            }

            return [$existing, null, []];
        }

        // Nexus's own id equals the producer's when it is free (nothing changes for existing producers); when another application
        // already holds that id here, this event gets a fresh one so consumers still de-duplicate on a globally unique `event_id`.
        $eventId = Event::query()->whereKey($producerEventId)->exists() ? (string) Str::uuid() : $producerEventId;

        try {
            $event = Event::create([
                'id' => $eventId,
                'producer_event_id' => $producerEventId,
                'event_key' => $envelope['event_key'],
                'event_version' => $envelope['event_version'] ?? $catalogEntry->schema_version,
                'occurred_at' => $envelope['occurred_at'] ?? now(),
                'source_application_id' => $producer->application_id,
                'producer_service_account_id' => $producer->id,
                'tenant_id' => $envelope['tenant_id'] ?? null,
                'correlation_id' => $envelope['correlation_id'] ?? (string) Str::uuid(),
                'causation_id' => $envelope['causation_id'] ?? null,
                'data' => $data,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost a race against a concurrent identical delivery.
            $raced = $this->findByProducerId($producer, $producerEventId);
            if (! $raced) {
                throw new \RuntimeException('Event id collision could not be resolved.');
            }

            return [$raced, null, []];
        }

        $this->dispatchToConsumers($event);

        return [$event, null, []];
    }

    /**
     * An event OptiNexus publishes itself (see PlatformEvents): no producing application, always about one tenant. Fans out exactly like an
     * ingested event, so only subscribed applications assigned to the tenant receive it. Failures here must never fail the caller's change.
     *
     * @param  array<string, mixed>  $data
     */
    public function publishPlatform(string $eventKey, Tenant $tenant, array $data): Event
    {
        $definition = PlatformEvents::catalog()[$eventKey] ?? throw new \InvalidArgumentException("[{$eventKey}] is not a platform event.");

        // `events.event_key` references the catalog. EcosystemCatalogSeeder registers the keys, but an installation that was not
        // seeded (or seeded before this release) must not fail here, so the entry is made sure of.
        EventCatalogEntry::query()->firstOrCreate(['event_key' => $eventKey], [
            'application_id' => null,
            'name' => $definition['name'],
            'description' => $definition['description'],
            'schema_version' => '1',
            'payload_schema' => PlatformEvents::PAYLOAD_SCHEMA,
            'status' => EventCatalogEntry::STATUS_ACTIVE,
        ]);

        $id = (string) Str::uuid();
        $event = Event::create([
            'id' => $id,
            'producer_event_id' => $id,
            'event_key' => $eventKey,
            'event_version' => '1',
            'occurred_at' => now(),
            'source_application_id' => null,
            'producer_service_account_id' => null,
            'tenant_id' => $tenant->id,
            'correlation_id' => (string) Str::uuid(),
            'data' => $data,
        ]);

        $this->dispatchToConsumers($event);

        return $event;
    }

    private function findByProducerId(ServiceAccount $producer, string $producerEventId): ?Event
    {
        return Event::query()
            ->where('producer_event_id', $producerEventId)
            ->where('source_application_id', $producer->application_id)
            ->first();
    }

    /**
     * An event key belongs to the application it is registered to, and only
     * that application's service accounts may send it, so one application can
     * never speak with another's name (optifleet.* from OptiRadar's token).
     * A key without an application is a platform key and a service account
     * without an application is a platform account; they only fit each other.
     */
    private function producerOwnsKey(ServiceAccount $producer, EventCatalogEntry $entry): bool
    {
        return $entry->application_id === $producer->application_id;
    }

    /**
     * Same facts, same shape, whatever the key order: PostgreSQL's jsonb hands
     * keys back in its own order, so a plain comparison would call every retry
     * of a multi-field event a conflict.
     */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn ($item) => $this->canonical($item), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function dispatchToConsumers(Event $event): void
    {
        $workflows = Workflow::query()
            ->where('trigger_type', Workflow::TRIGGER_EVENT)
            ->where('trigger_event_key', $event->event_key)
            ->where('status', Workflow::STATUS_ACTIVE)
            ->get();

        foreach ($workflows as $workflow) {
            $this->queueDelivery($event, EventDelivery::CONSUMER_WORKFLOW, $workflow->id);
        }

        $rules = NotificationRule::query()
            ->where('trigger_event_key', $event->event_key)
            ->where('status', NotificationRule::STATUS_ACTIVE)
            ->get();

        foreach ($rules as $rule) {
            $this->queueDelivery($event, EventDelivery::CONSUMER_NOTIFICATION, $rule->id);
        }

        // Event broker: subscribed applications get a signed webhook, only for tenants they are assigned to.
        foreach ($this->subscriptions->recipients($event) as $subscription) {
            $this->queueDelivery($event, EventDelivery::CONSUMER_WEBHOOK, $subscription->id);
        }
    }

    private function queueDelivery(Event $event, string $consumerType, string $consumerReference): void
    {
        $delivery = EventDelivery::create([
            'event_id' => $event->id,
            'consumer_type' => $consumerType,
            'consumer_reference' => $consumerReference,
            'status' => EventDelivery::STATUS_PENDING,
        ]);

        if ($consumerType !== EventDelivery::CONSUMER_WEBHOOK) {
            DeliverEventToConsumer::dispatch($delivery->id);

            return;
        }

        try {
            DeliverWebhook::dispatch($delivery->id);
        } catch (\Throwable $e) {
            // Only reachable with the sync queue: a subscriber's failure must never fail the producer's request. The delivery
            // keeps its state (FAILED after the job's failed()) for an operator to retry.
            report($e);
        }
    }
}
