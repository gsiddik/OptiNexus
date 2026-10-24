<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EventSubscriptionResource;
use App\Models\Application;
use App\Models\EventSubscription;
use App\Services\AuditService;
use App\Services\Event\EventSubscriptionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Event broker subscriptions (operator API). The signing secret is returned once, when a subscription is created or its
 * secret rotated, and is never listed or audited.
 */
class EventSubscriptionController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly EventSubscriptionService $subscriptions,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = EventSubscription::query()->with('application');
        foreach (['application_id', 'tenant_id', 'status'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        return $this->paginated($query->orderBy('event_key_pattern')->paginate(min(100, (int) $request->query('per_page', 25))), EventSubscriptionResource::class);
    }

    public function show(EventSubscription $subscription): JsonResponse
    {
        return $this->ok(new EventSubscriptionResource($subscription->load('application')));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'application_id' => ['required', 'uuid', 'exists:applications,id'],
            'event_key_pattern' => ['required', 'string', 'max:150'],
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'endpoint_url' => ['required', 'string', 'max:2048'],
        ]);
        $this->check($data);

        $application = Application::query()->findOrFail($data['application_id']);
        if ($application->application_code === 'cgo') {
            throw ValidationException::withMessages(['application_id' => 'The governance application cannot subscribe to events.']);
        }

        try {
            [$subscription, $secret] = $this->subscriptions->create($application, $data['event_key_pattern'], $data['tenant_id'] ?? null, $data['endpoint_url'], $request->user()?->id);
        } catch (UniqueConstraintViolationException) {
            return $this->fail('CONFLICT', 'This application already subscribes to this pattern for this tenant scope.', 409);
        }

        $this->audit->record('event.subscription.created', $request, resourceType: 'EventSubscription', resourceId: $subscription->id, newValue: $this->auditable($subscription), applicationId: $application->id, tenantId: $subscription->tenant_id);

        return $this->created([...(new EventSubscriptionResource($subscription->load('application')))->toArray($request), 'signing_secret' => $secret]);
    }

    public function update(Request $request, EventSubscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'endpoint_url' => ['sometimes', 'string', 'max:2048'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', EventSubscription::STATUSES)],
        ]);
        $this->check($data);

        $old = $this->auditable($subscription);
        $subscription->update($data);
        $this->audit->record('event.subscription.updated', $request, resourceType: 'EventSubscription', resourceId: $subscription->id, oldValue: $old, newValue: $this->auditable($subscription), applicationId: $subscription->application_id, tenantId: $subscription->tenant_id);

        return $this->ok(new EventSubscriptionResource($subscription->load('application')));
    }

    public function rotateSecret(Request $request, EventSubscription $subscription): JsonResponse
    {
        $secret = $this->subscriptions->rotate($subscription);
        $this->audit->record('event.subscription.secret_rotated', $request, resourceType: 'EventSubscription', resourceId: $subscription->id, applicationId: $subscription->application_id, tenantId: $subscription->tenant_id);

        return $this->ok([...(new EventSubscriptionResource($subscription->load('application')))->toArray($request), 'signing_secret' => $secret]);
    }

    /** @param array<string, mixed> $data */
    private function check(array $data): void
    {
        if (isset($data['event_key_pattern']) && ($problem = $this->subscriptions->patternProblem($data['event_key_pattern']))) {
            throw ValidationException::withMessages(['event_key_pattern' => $problem]);
        }
        if (isset($data['endpoint_url']) && ($problem = $this->subscriptions->endpointProblem($data['endpoint_url']))) {
            throw ValidationException::withMessages(['endpoint_url' => $problem]);
        }
    }

    /** @return array<string, mixed> */
    private function auditable(EventSubscription $subscription): array
    {
        return $subscription->only(['application_id', 'event_key_pattern', 'tenant_id', 'endpoint_url', 'status']);
    }
}
