<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EventDeliveryResource;
use App\Jobs\DeliverEventToConsumer;
use App\Models\EventDelivery;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventDeliveryController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = EventDelivery::query();

        foreach (['event_id', 'consumer_type', 'status'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25));

        return $this->paginated($paginator, EventDeliveryResource::class);
    }

    public function show(EventDelivery $delivery): JsonResponse
    {
        return $this->ok(new EventDeliveryResource($delivery));
    }

    public function retry(Request $request, EventDelivery $delivery): JsonResponse
    {
        if (! in_array($delivery->status, [EventDelivery::STATUS_FAILED], true)) {
            return $this->fail('CONFLICT', 'Only a failed delivery can be retried.', 409);
        }

        $delivery->update(['status' => EventDelivery::STATUS_PENDING, 'next_retry_at' => null]);
        DeliverEventToConsumer::dispatch($delivery->id);

        $this->audit->record('event.delivery.retried', $request, resourceType: 'EventDelivery', resourceId: $delivery->id);

        return $this->ok(new EventDeliveryResource($delivery));
    }

    public function discard(Request $request, EventDelivery $delivery): JsonResponse
    {
        if (in_array($delivery->status, [EventDelivery::STATUS_DELIVERED, EventDelivery::STATUS_DISCARDED], true)) {
            return $this->fail('CONFLICT', 'This delivery cannot be discarded from its current status.', 409);
        }

        $delivery->update(['status' => EventDelivery::STATUS_DISCARDED]);

        $this->audit->record('event.delivery.discarded', $request, resourceType: 'EventDelivery', resourceId: $delivery->id);

        return $this->ok(new EventDeliveryResource($delivery));
    }
}
