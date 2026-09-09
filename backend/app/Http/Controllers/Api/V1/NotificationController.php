<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\SendNotificationRequest;
use App\Http\Resources\V1\NotificationResource;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Services\AuditService;
use App\Services\Notification\NotificationDispatchService;
use App\Support\Correlation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use ApiResponses;

    private const ERROR_STATUS = [
        'NOTIFICATION_NOT_RETRYABLE' => 409,
        'NOTIFICATION_NOT_CANCELLABLE' => 409,
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationDispatchService $dispatch,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Notification::query();

        foreach (['tenant_id', 'application_id', 'status', 'channel', 'correlation_id', 'recipient_user_id'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, NotificationResource::class);
    }

    public function show(Notification $notification): JsonResponse
    {
        return $this->ok(new NotificationResource($notification->load('deliveries')));
    }

    public function send(SendNotificationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $template = NotificationTemplate::where('template_code', $validated['template_code'])->first();

        if (! $template->isActive()) {
            return $this->fail('NOTIFICATION_TEMPLATE_INVALID', 'This notification template is not active.', 422);
        }

        $correlationId = Correlation::resolve($request, $validated['correlation_id'] ?? null);

        $notifications = $this->dispatch->sendDirect(
            $template,
            $validated['recipient_user_ids'],
            $validated['context'] ?? [],
            $validated['tenant_id'] ?? null,
            $validated['application_id'] ?? null,
            $correlationId,
            $request->user(),
        );

        foreach ($notifications as $notification) {
            $this->audit->record('notification.sent_manually', $request, resourceType: 'Notification', resourceId: $notification->id, newValue: ['recipient_user_id' => $notification->recipient_user_id, 'channel' => $notification->channel], tenantId: $notification->tenant_id, applicationId: $notification->application_id, correlationId: $correlationId);
        }

        return $this->created(NotificationResource::collection($notifications));
    }

    public function retry(Request $request, Notification $notification): JsonResponse
    {
        $error = $this->dispatch->retry($notification);

        if ($error) {
            return $this->fail($error, 'This notification cannot be retried in its current state.', self::ERROR_STATUS[$error] ?? 422);
        }

        $this->audit->record('notification.retried', $request, resourceType: 'Notification', resourceId: $notification->id, tenantId: $notification->tenant_id, applicationId: $notification->application_id, correlationId: $notification->correlation_id);

        return $this->ok(new NotificationResource($notification));
    }

    public function cancel(Request $request, Notification $notification): JsonResponse
    {
        $error = $this->dispatch->cancel($notification);

        if ($error) {
            return $this->fail($error, 'This notification cannot be cancelled in its current state.', self::ERROR_STATUS[$error] ?? 422);
        }

        $this->audit->record('notification.cancelled', $request, resourceType: 'Notification', resourceId: $notification->id, tenantId: $notification->tenant_id, applicationId: $notification->application_id, correlationId: $notification->correlation_id);

        return $this->ok(new NotificationResource($notification));
    }
}
