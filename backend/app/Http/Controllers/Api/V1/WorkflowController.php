<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreWorkflowRequest;
use App\Http\Requests\V1\UpdateWorkflowRequest;
use App\Http\Resources\V1\WorkflowResource;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\AuditService;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkflowController extends Controller
{
    use ApiResponses;

    private const EXECUTE_ERROR_STATUS = [
        'WORKFLOW_NOT_ACTIVE' => 422,
        'WORKFLOW_INVALID' => 422,
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly WorkflowDefinitionService $definitions,
        private readonly WorkflowExecutionService $execution,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Workflow::query();

        foreach (['tenant_id', 'application_id', 'status', 'trigger_type'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, WorkflowResource::class);
    }

    public function store(StoreWorkflowRequest $request): JsonResponse
    {
        $data = $request->validated();

        $errors = $this->definitions->validateStructure($data['steps'], $data['transitions'] ?? []);
        if ($errors) {
            return $this->fail('WORKFLOW_INVALID', 'The workflow definition is invalid.', 422, ['errors' => $errors]);
        }

        $workflow = DB::transaction(function () use ($data, $request) {
            $workflow = Workflow::create([
                'workflow_code' => $data['workflow_code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'tenant_id' => $data['tenant_id'] ?? null,
                'application_id' => $data['application_id'] ?? null,
                'status' => Workflow::STATUS_DRAFT,
                'current_version' => 0,
                'trigger_type' => $data['trigger_type'],
                'trigger_event_key' => $data['trigger_event_key'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $version = $workflow->versions()->create([
                'version' => 1,
                'status' => WorkflowVersion::STATUS_DRAFT,
                'created_by' => $request->user()?->id,
            ]);

            $this->definitions->replaceDraftDefinition($version, $data['steps'], $data['transitions'] ?? []);

            return $workflow;
        });

        $this->audit->record('workflow.created', $request, resourceType: 'Workflow', resourceId: $workflow->id, newValue: $workflow->toArray(), tenantId: $workflow->tenant_id, applicationId: $workflow->application_id);

        return $this->created(new WorkflowResource($workflow->load('draftVersion.steps', 'draftVersion.transitions')));
    }

    public function show(Workflow $workflow): JsonResponse
    {
        return $this->ok(new WorkflowResource($workflow->load('draftVersion.steps', 'draftVersion.transitions')));
    }

    public function update(UpdateWorkflowRequest $request, Workflow $workflow): JsonResponse
    {
        $data = $request->validated();
        $old = $workflow->toArray();

        if (isset($data['name']) || array_key_exists('description', $data)) {
            $workflow->update(array_intersect_key($data, array_flip(['name', 'description'])));
        }

        if (isset($data['steps'])) {
            $errors = $this->definitions->validateStructure($data['steps'], $data['transitions'] ?? []);
            if ($errors) {
                return $this->fail('WORKFLOW_INVALID', 'The workflow definition is invalid.', 422, ['errors' => $errors]);
            }

            $draft = $workflow->draftVersion()->first();
            if (! $draft) {
                $draft = $workflow->versions()->create([
                    'version' => $workflow->current_version + 1,
                    'status' => WorkflowVersion::STATUS_DRAFT,
                    'created_by' => $request->user()?->id,
                ]);
            }

            $this->definitions->replaceDraftDefinition($draft, $data['steps'], $data['transitions'] ?? []);
        }

        $this->audit->record('workflow.updated', $request, resourceType: 'Workflow', resourceId: $workflow->id, oldValue: $old, newValue: $workflow->toArray(), tenantId: $workflow->tenant_id, applicationId: $workflow->application_id);

        return $this->ok(new WorkflowResource($workflow->load('draftVersion.steps', 'draftVersion.transitions')));
    }

    public function clone(Request $request, Workflow $workflow): JsonResponse
    {
        $validated = $request->validate([
            'workflow_code' => ['required', 'string', 'max:64', 'unique:workflows,workflow_code'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $source = $workflow->activeVersion() ?? $workflow->draftVersion()->first();
        if (! $source) {
            return $this->fail('WORKFLOW_INVALID', 'This workflow has no version to clone from.', 422);
        }
        $source->load('steps', 'transitions');

        $clone = DB::transaction(function () use ($workflow, $validated, $source, $request) {
            $clone = Workflow::create([
                'workflow_code' => $validated['workflow_code'],
                'name' => $validated['name'],
                'description' => $workflow->description,
                'tenant_id' => $workflow->tenant_id,
                'application_id' => $workflow->application_id,
                'status' => Workflow::STATUS_DRAFT,
                'current_version' => 0,
                'trigger_type' => $workflow->trigger_type,
                'trigger_event_key' => $workflow->trigger_event_key,
                'created_by' => $request->user()?->id,
            ]);

            $version = $clone->versions()->create(['version' => 1, 'status' => WorkflowVersion::STATUS_DRAFT, 'created_by' => $request->user()?->id]);

            $steps = $source->steps->map(fn ($s) => ['step_key' => $s->step_key, 'step_type' => $s->step_type, 'name' => $s->name, 'config' => $s->config, 'sort_order' => $s->sort_order])->all();
            $stepIdToKey = $source->steps->pluck('step_key', 'id');
            $transitions = $source->transitions->map(fn ($t) => [
                'from_step_key' => $stepIdToKey[$t->from_step_id], 'to_step_key' => $stepIdToKey[$t->to_step_id], 'condition' => $t->condition, 'sort_order' => $t->sort_order,
            ])->all();

            $this->definitions->replaceDraftDefinition($version, $steps, $transitions);

            return $clone;
        });

        $this->audit->record('workflow.cloned', $request, resourceType: 'Workflow', resourceId: $clone->id, newValue: ['cloned_from' => $workflow->id], tenantId: $clone->tenant_id, applicationId: $clone->application_id);

        return $this->created(new WorkflowResource($clone->load('draftVersion.steps', 'draftVersion.transitions')));
    }

    public function validateDefinition(Workflow $workflow): JsonResponse
    {
        $draft = $workflow->draftVersion()->first();
        if (! $draft) {
            return $this->fail('WORKFLOW_INVALID', 'This workflow has no draft version to validate.', 422);
        }

        $draft->load('steps', 'transitions');
        $steps = $draft->steps->map(fn ($s) => ['step_key' => $s->step_key, 'step_type' => $s->step_type, 'config' => $s->config])->all();
        $stepIdToKey = $draft->steps->pluck('step_key', 'id');
        $transitions = $draft->transitions->map(fn ($t) => ['from_step_key' => $stepIdToKey[$t->from_step_id] ?? null, 'to_step_key' => $stepIdToKey[$t->to_step_id] ?? null, 'condition' => $t->condition])->all();

        $errors = $this->definitions->validateStructure($steps, $transitions);

        return $this->ok(['valid' => empty($errors), 'errors' => $errors]);
    }

    public function activate(Request $request, Workflow $workflow): JsonResponse
    {
        $draft = $workflow->draftVersion()->first();
        if (! $draft) {
            return $this->fail('WORKFLOW_INVALID', 'This workflow has no draft version to activate.', 422);
        }

        $draft->load('steps', 'transitions');
        $steps = $draft->steps->map(fn ($s) => ['step_key' => $s->step_key, 'step_type' => $s->step_type, 'config' => $s->config])->all();
        $stepIdToKey = $draft->steps->pluck('step_key', 'id');
        $transitions = $draft->transitions->map(fn ($t) => ['from_step_key' => $stepIdToKey[$t->from_step_id] ?? null, 'to_step_key' => $stepIdToKey[$t->to_step_id] ?? null, 'condition' => $t->condition])->all();

        $errors = $this->definitions->validateStructure($steps, $transitions);
        if ($errors) {
            return $this->fail('WORKFLOW_INVALID', 'The draft version failed validation and cannot be activated.', 422, ['errors' => $errors]);
        }

        DB::transaction(function () use ($workflow, $draft) {
            $workflow->versions()->where('status', WorkflowVersion::STATUS_ACTIVE)->update(['status' => WorkflowVersion::STATUS_RETIRED, 'retired_at' => now()]);
            $draft->update(['status' => WorkflowVersion::STATUS_ACTIVE, 'activated_at' => now()]);
            $workflow->update(['status' => Workflow::STATUS_ACTIVE, 'current_version' => $draft->version]);
        });

        $this->audit->record('workflow.activated', $request, resourceType: 'Workflow', resourceId: $workflow->id, newValue: ['version' => $draft->version], tenantId: $workflow->tenant_id, applicationId: $workflow->application_id);

        return $this->ok(new WorkflowResource($workflow->refresh()->load('draftVersion.steps', 'draftVersion.transitions')));
    }

    public function deactivate(Request $request, Workflow $workflow): JsonResponse
    {
        if ($workflow->status !== Workflow::STATUS_ACTIVE) {
            return $this->fail('INVALID_STATE_TRANSITION', "Cannot deactivate a workflow in status [{$workflow->status}].", 409);
        }

        $workflow->update(['status' => Workflow::STATUS_INACTIVE]);

        $this->audit->record('workflow.deactivated', $request, resourceType: 'Workflow', resourceId: $workflow->id, tenantId: $workflow->tenant_id, applicationId: $workflow->application_id);

        return $this->ok(new WorkflowResource($workflow));
    }

    /**
     * Manual/API trigger. Requires cgo.workflow.execute explicitly - never
     * implied by view/update/activate.
     */
    public function execute(Request $request, Workflow $workflow): JsonResponse
    {
        $validated = $request->validate([
            'payload' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:150'],
            'correlation_id' => ['nullable', 'string', 'max:150'],
        ]);

        [$instance, $error] = $this->execution->trigger(
            $workflow,
            \App\Models\Workflow::TRIGGER_MANUAL,
            $validated['payload'] ?? [],
            $validated['correlation_id'] ?? null,
            null,
            $validated['idempotency_key'] ?? null,
            $request->user(),
        );

        if ($error) {
            return $this->fail($error, 'The workflow could not be triggered.', self::EXECUTE_ERROR_STATUS[$error] ?? 422);
        }

        return $this->created(new \App\Http\Resources\V1\WorkflowInstanceResource($instance));
    }
}
