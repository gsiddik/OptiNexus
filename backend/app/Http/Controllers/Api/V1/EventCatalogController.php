<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreEventCatalogEntryRequest;
use App\Http\Resources\V1\EventCatalogEntryResource;
use App\Models\EventCatalogEntry;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventCatalogController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = EventCatalogEntry::query();

        foreach (['application_id', 'status'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('event_key', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"));
        }

        $paginator = $query->orderBy('event_key')->paginate((int) $request->query('per_page', 25));

        return $this->paginated($paginator, EventCatalogEntryResource::class);
    }

    public function store(StoreEventCatalogEntryRequest $request): JsonResponse
    {
        $entry = EventCatalogEntry::create([...$request->validated(), 'status' => EventCatalogEntry::STATUS_ACTIVE]);

        $this->audit->record('event.catalog.registered', $request, resourceType: 'EventCatalogEntry', resourceId: $entry->id, newValue: $entry->toArray(), applicationId: $entry->application_id);

        return $this->created(new EventCatalogEntryResource($entry));
    }

    public function show(EventCatalogEntry $event): JsonResponse
    {
        return $this->ok(new EventCatalogEntryResource($event));
    }

    public function update(Request $request, EventCatalogEntry $event): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'schema_version' => ['sometimes', 'string', 'max:20'],
            'payload_schema' => ['nullable', 'array'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', EventCatalogEntry::STATUSES)],
        ]);

        $old = $event->toArray();
        $event->update($validated);

        $this->audit->record('event.catalog.updated', $request, resourceType: 'EventCatalogEntry', resourceId: $event->id, oldValue: $old, newValue: $event->toArray(), applicationId: $event->application_id);

        return $this->ok(new EventCatalogEntryResource($event));
    }
}
