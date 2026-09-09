<?php

namespace App\Services\Event;

use App\Jobs\DeliverEventToConsumer;
use App\Models\Event;
use App\Models\EventCatalogEntry;
use App\Models\EventDelivery;
use App\Models\NotificationRule;
use App\Models\ServiceAccount;
use App\Models\Workflow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Event ingestion: validates the producer's event_key against the Event
 * Catalog, checks payload shape, and writes the immutable Event row
 * idempotently (same event_id twice -> the same row, never a duplicate
 * fact). Only a genuinely NEW event fans out to consumers (a matching
 * ACTIVE event-triggered Workflow, or a matching ACTIVE NotificationRule)
 * - an idempotent replay never re-fires side effects. Each fan-out gets
 * its own EventDelivery row (outbox-style) so one consumer's failure
 * never blocks another's delivery.
 */
class EventDispatchService
{
    public function __construct(private readonly PayloadSchemaValidator $schemaValidator) {}

    /**
     * @return array{0: ?Event, 1: ?string, 2: array} [event, errorCode, errorDetails]
     */
    public function ingest(array $envelope, ServiceAccount $producer): array
    {
        $catalogEntry = EventCatalogEntry::query()->where('event_key', $envelope['event_key'])->first();

        if (! $catalogEntry) {
            return [null, 'EVENT_INVALID', ['reason' => 'Unknown event_key. Register it in the Event Catalog first.']];
        }

        if (! $catalogEntry->isActive()) {
            return [null, 'EVENT_INVALID', ['reason' => 'This event_key is not ACTIVE in the Event Catalog.']];
        }

        $data = $envelope['data'] ?? [];
        $schemaErrors = $this->schemaValidator->validate($data, $catalogEntry->payload_schema);
        if ($schemaErrors) {
            return [null, 'EVENT_SCHEMA_INVALID', ['errors' => $schemaErrors]];
        }

        $eventId = $envelope['event_id'] ?? (string) Str::uuid();

        $existing = Event::find($eventId);
        if ($existing) {
            // A genuine idempotent retry (same id, same fact) replays the
            // original record; the same id reused for a *different* fact is
            // a real conflict, not a safe-to-ignore duplicate.
            if ($existing->event_key !== $envelope['event_key'] || $existing->data !== $data) {
                return [null, 'EVENT_DUPLICATE', ['reason' => 'event_id already used for a different event.']];
            }

            return [$existing, null, []];
        }

        try {
            $event = Event::create([
                'id' => $eventId,
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
            return [Event::findOrFail($eventId), null, []];
        }

        $this->dispatchToConsumers($event);

        return [$event, null, []];
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
    }

    private function queueDelivery(Event $event, string $consumerType, string $consumerReference): void
    {
        $delivery = EventDelivery::create([
            'event_id' => $event->id,
            'consumer_type' => $consumerType,
            'consumer_reference' => $consumerReference,
            'status' => EventDelivery::STATUS_PENDING,
        ]);

        DeliverEventToConsumer::dispatch($delivery->id);
    }
}
