<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\WorkflowInstanceResource;
use App\Models\WorkflowInstance;
use App\Services\AuditService;
use App\Services\Workflow\WorkflowExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowInstanceController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly WorkflowExecutionService $execution,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = WorkflowInstance::query();

        foreach (['workflow_id', 'tenant_id', 'application_id', 'status', 'correlation_id'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25));

        return $this->paginated($paginator, WorkflowInstanceResource::class);
    }

    public function show(WorkflowInstance $instance): JsonResponse
    {
        return $this->ok(new WorkflowInstanceResource($instance->load('steps.step')));
    }

    public function retry(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $error = $this->execution->retry($instance);

        if ($error) {
            return $this->fail($error, 'This workflow instance cannot be retried from its current state.', $error === 'INVALID_STATE_TRANSITION' ? 409 : 422);
        }

        $this->audit->record('workflow.instance.retried', $request, resourceType: 'WorkflowInstance', resourceId: $instance->id, tenantId: $instance->tenant_id, applicationId: $instance->application_id, correlationId: $instance->correlation_id);

        return $this->ok(new WorkflowInstanceResource($instance->refresh()->load('steps.step')));
    }

    public function cancel(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $error = $this->execution->cancel($instance);

        if ($error) {
            return $this->fail($error, 'This workflow instance is already completed and cannot be cancelled.', 409);
        }

        $this->audit->record('workflow.instance.cancelled', $request, resourceType: 'WorkflowInstance', resourceId: $instance->id, tenantId: $instance->tenant_id, applicationId: $instance->application_id, correlationId: $instance->correlation_id);

        return $this->ok(new WorkflowInstanceResource($instance->refresh()->load('steps.step')));
    }
}
