<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreNotificationRuleRequest;
use App\Http\Requests\V1\UpdateNotificationRuleRequest;
use App\Http\Resources\V1\NotificationRuleResource;
use App\Models\NotificationRule;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationRuleController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = NotificationRule::query();

        foreach (['tenant_id', 'application_id', 'status', 'trigger_event_key'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, NotificationRuleResource::class);
    }

    public function store(StoreNotificationRuleRequest $request): JsonResponse
    {
        $rule = NotificationRule::create([
            ...$request->validated(),
            'status' => NotificationRule::STATUS_ACTIVE,
            'created_by' => $request->user()?->id,
        ]);

        $this->audit->record('notification.rule.created', $request, resourceType: 'NotificationRule', resourceId: $rule->id, newValue: $rule->toArray(), tenantId: $rule->tenant_id, applicationId: $rule->application_id);

        return $this->created(new NotificationRuleResource($rule));
    }

    public function show(NotificationRule $notificationRule): JsonResponse
    {
        return $this->ok(new NotificationRuleResource($notificationRule));
    }

    public function update(UpdateNotificationRuleRequest $request, NotificationRule $notificationRule): JsonResponse
    {
        $old = $notificationRule->toArray();
        $notificationRule->update($request->validated());

        $this->audit->record('notification.rule.updated', $request, resourceType: 'NotificationRule', resourceId: $notificationRule->id, oldValue: $old, newValue: $notificationRule->toArray(), tenantId: $notificationRule->tenant_id, applicationId: $notificationRule->application_id);

        return $this->ok(new NotificationRuleResource($notificationRule));
    }
}
