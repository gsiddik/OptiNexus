<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreNotificationTemplateRequest;
use App\Http\Requests\V1\UpdateNotificationTemplateRequest;
use App\Http\Resources\V1\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationTemplateController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = NotificationTemplate::query();

        foreach (['channel', 'status'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, NotificationTemplateResource::class);
    }

    public function store(StoreNotificationTemplateRequest $request): JsonResponse
    {
        $template = NotificationTemplate::create([
            ...$request->validated(),
            'status' => NotificationTemplate::STATUS_DRAFT,
            'created_by' => $request->user()?->id,
        ]);

        $this->audit->record('notification.template.created', $request, resourceType: 'NotificationTemplate', resourceId: $template->id, newValue: $template->toArray());

        return $this->created(new NotificationTemplateResource($template));
    }

    public function show(NotificationTemplate $notificationTemplate): JsonResponse
    {
        return $this->ok(new NotificationTemplateResource($notificationTemplate));
    }

    public function update(UpdateNotificationTemplateRequest $request, NotificationTemplate $notificationTemplate): JsonResponse
    {
        $old = $notificationTemplate->toArray();
        $data = $request->validated();

        if (array_key_exists('body_template', $data) || array_key_exists('subject_template', $data)) {
            $data['version'] = $notificationTemplate->version + 1;
        }

        $notificationTemplate->update($data);

        $this->audit->record('notification.template.updated', $request, resourceType: 'NotificationTemplate', resourceId: $notificationTemplate->id, oldValue: $old, newValue: $notificationTemplate->toArray());

        return $this->ok(new NotificationTemplateResource($notificationTemplate));
    }
}
