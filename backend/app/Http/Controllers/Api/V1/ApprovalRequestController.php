<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ApprovalRequestResource;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Services\Approval\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalRequestController extends Controller
{
    use ApiResponses;

    private const ERROR_STATUS = [
        'APPROVAL_NOT_PENDING' => 409,
        'APPROVAL_NOT_ALLOWED' => 403,
        'SELF_APPROVAL_DENIED' => 403,
        'APPROVAL_EXPIRED' => 409,
    ];

    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): JsonResponse
    {
        $query = ApprovalRequest::query();

        foreach (['approval_definition_id', 'tenant_id', 'status', 'workflow_instance_id', 'correlation_id'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25));

        return $this->paginated($paginator, ApprovalRequestResource::class);
    }

    public function show(ApprovalRequest $approvalRequest): JsonResponse
    {
        return $this->ok(new ApprovalRequestResource($approvalRequest->load('steps.decision')));
    }

    public function approve(Request $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        return $this->decide($request, $approvalRequest, ApprovalDecision::DECISION_APPROVE);
    }

    public function reject(Request $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        return $this->decide($request, $approvalRequest, ApprovalDecision::DECISION_REJECT);
    }

    public function return(Request $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        return $this->decide($request, $approvalRequest, ApprovalDecision::DECISION_RETURN);
    }

    private function decide(Request $request, ApprovalRequest $approvalRequest, string $decision): JsonResponse
    {
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        $error = $this->approvals->decide($approvalRequest, $request->user(), $decision, $validated['comment'] ?? null);

        if ($error) {
            return $this->fail($error, $this->humanize($error), self::ERROR_STATUS[$error] ?? 422);
        }

        return $this->ok(new ApprovalRequestResource($approvalRequest->refresh()->load('steps.decision')));
    }

    private function humanize(string $code): string
    {
        return match ($code) {
            'APPROVAL_NOT_PENDING' => 'This request is not pending a decision (already decided, cancelled, or this step was already decided).',
            'APPROVAL_NOT_ALLOWED' => 'You are not an authorized approver for this request at its current level.',
            'SELF_APPROVAL_DENIED' => 'You cannot approve a request you submitted yourself.',
            'APPROVAL_EXPIRED' => 'This approval request has expired.',
            default => 'The decision could not be recorded.',
        };
    }
}
