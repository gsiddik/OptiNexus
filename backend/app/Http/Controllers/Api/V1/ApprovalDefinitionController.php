<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreApprovalDefinitionRequest;
use App\Http\Resources\V1\ApprovalDefinitionResource;
use App\Models\ApprovalDefinition;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprovalDefinitionController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = ApprovalDefinition::query();

        foreach (['tenant_id', 'application_id', 'status'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, ApprovalDefinitionResource::class);
    }

    public function store(StoreApprovalDefinitionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $definition = DB::transaction(function () use ($data, $request) {
            $definition = ApprovalDefinition::create([
                'definition_code' => $data['definition_code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'tenant_id' => $data['tenant_id'] ?? null,
                'application_id' => $data['application_id'] ?? null,
                'rule_type' => $data['rule_type'] ?? ApprovalDefinition::RULE_SEQUENTIAL,
                'status' => ApprovalDefinition::STATUS_DRAFT,
                'self_approval_allowed' => $data['self_approval_allowed'] ?? false,
                'expires_after_hours' => $data['expires_after_hours'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($data['levels'] as $level) {
                $definition->levels()->create([
                    'level_order' => $level['level_order'],
                    'name' => $level['name'],
                    'approver_type' => $level['approver_type'],
                    'approver_reference' => $level['approver_reference'] ?? null,
                    'condition' => $level['condition'] ?? null,
                ]);
            }

            return $definition;
        });

        $this->audit->record('approval.definition.created', $request, resourceType: 'ApprovalDefinition', resourceId: $definition->id, newValue: $definition->toArray(), tenantId: $definition->tenant_id, applicationId: $definition->application_id);

        return $this->created(new ApprovalDefinitionResource($definition->load('levels')));
    }

    public function show(ApprovalDefinition $definition): JsonResponse
    {
        return $this->ok(new ApprovalDefinitionResource($definition->load('levels')));
    }

    public function update(Request $request, ApprovalDefinition $definition): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', ApprovalDefinition::STATUSES)],
            'self_approval_allowed' => ['sometimes', 'boolean'],
            'expires_after_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ]);

        $old = $definition->toArray();
        $definition->update($validated);

        $this->audit->record('approval.definition.updated', $request, resourceType: 'ApprovalDefinition', resourceId: $definition->id, oldValue: $old, newValue: $definition->toArray(), tenantId: $definition->tenant_id, applicationId: $definition->application_id);

        return $this->ok(new ApprovalDefinitionResource($definition->load('levels')));
    }
}
