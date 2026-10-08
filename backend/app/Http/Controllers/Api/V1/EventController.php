<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreEventRequest;
use App\Http\Resources\V1\EventResource;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Services\AuditService;
use App\Services\Event\EventDispatchService;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    use ApiResponses;

    private const MAX_PAYLOAD_BYTES = 65536;

    public function __construct(
        private readonly EventDispatchService $dispatch,
        private readonly AuditService $audit,
    ) {}

    /**
     * Machine-to-machine only. The producer's identity comes from the
     * authenticated service account (event.write scope), never from the
     * request body, preventing event spoofing, and it may only send the event
     * keys registered to its own application. Idempotent on event_id.
     */
    public function store(StoreEventRequest $request): JsonResponse
    {
        /** @var ServiceAccount $producer */
        $producer = $request->attributes->get('service_account');
        $data = $request->validated();

        if (strlen(json_encode($data['data'] ?? [])) > self::MAX_PAYLOAD_BYTES) {
            return $this->fail('EVENT_INVALID', 'Event payload exceeds the maximum allowed size.', 422);
        }

        if (! empty($data['tenant_id']) && $producer->application_id) {
            $tenant = Tenant::query()->find($data['tenant_id']);
            $assigned = $tenant?->applications()->where('applications.id', $producer->application_id)->wherePivot('status', 'ACTIVE')->exists();
            if (! $assigned) {
                return $this->fail('EVENT_SOURCE_DENIED', 'This application is not assigned to the given tenant.', 403);
            }
        }

        [$event, $errorCode, $details] = $this->dispatch->ingest($data, $producer);

        if ($errorCode) {
            $status = match ($errorCode) {
                'EVENT_DUPLICATE' => 409,
                'EVENT_SOURCE_DENIED' => 403,
                default => 422,
            };

            return $this->fail($errorCode, 'The event could not be accepted.', $status, $details);
        }

        $wasCreated = $event->wasRecentlyCreated;

        if ($wasCreated) {
            $this->audit->record(
                'event.received',
                $request,
                actorIdentity: $producer->name,
                tenantId: $event->tenant_id,
                applicationId: $event->source_application_id,
                resourceType: 'Event',
                resourceId: $event->id,
                newValue: ['event_key' => $event->event_key],
                source: $event->sourceApplication?->application_code ?? 'external',
                correlationId: $event->correlation_id,
                causationId: $event->causation_id,
            );
        }

        return $wasCreated
            ? $this->created(new EventResource($event))
            : $this->ok(new EventResource($event));
    }
}
