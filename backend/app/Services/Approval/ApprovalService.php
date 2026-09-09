<?php

namespace App\Services\Approval;

use App\Models\ApprovalDecision;
use App\Models\ApprovalDefinition;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalLevel;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRequestStep;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowInstanceStep;
use App\Models\WorkflowStep;
use App\Services\AuditService;
use App\Services\Workflow\WorkflowExecutionService;
use Illuminate\Support\Facades\DB;

/**
 * Approval as a first-class domain: definitions/levels describe WHO must
 * decide, requests/request_steps/decisions track ONE concrete decision
 * flow. Deliberately independent of Workflow - a request can be created
 * directly by a domain process, not only by a workflow's APPROVAL step.
 */
class ApprovalService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return array{0: ?ApprovalRequest, 1: ?string} [request, errorCode]
     */
    public function createRequest(
        ApprovalDefinition $definition,
        array $context = [],
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $workflowInstanceId = null,
        ?User $requestedBy = null,
        ?string $correlationId = null,
        ?string $tenantId = null,
    ): array {
        if (! $definition->isActive()) {
            return [null, 'APPROVAL_NOT_ALLOWED'];
        }

        $levels = $definition->levels;
        if ($levels->isEmpty()) {
            return [null, 'APPROVAL_NOT_ALLOWED'];
        }

        $request = DB::transaction(function () use ($definition, $context, $subjectType, $subjectId, $workflowInstanceId, $requestedBy, $correlationId, $tenantId, $levels) {
            $request = ApprovalRequest::create([
                'approval_definition_id' => $definition->id,
                'tenant_id' => $tenantId ?? $definition->tenant_id,
                'application_id' => $definition->application_id,
                'workflow_instance_id' => $workflowInstanceId,
                'requested_by' => $requestedBy?->id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'status' => ApprovalRequest::STATUS_PENDING,
                'current_level' => 1,
                'context' => $context,
                'correlation_id' => $correlationId,
                'expires_at' => $definition->expires_after_hours ? now()->addHours($definition->expires_after_hours) : null,
            ]);

            foreach ($levels as $level) {
                $request->steps()->create([
                    'approval_level_id' => $level->id,
                    'level_order' => $level->level_order,
                    'resolved_approver_user_id' => $this->resolveStaticApprover($level, $context),
                    'status' => ApprovalRequestStep::STATUS_PENDING,
                ]);
            }

            return $request;
        });

        $this->audit->record(
            'approval.requested',
            resourceType: 'ApprovalRequest',
            resourceId: $request->id,
            newValue: ['definition_id' => $definition->id, 'subject_type' => $subjectType, 'subject_id' => $subjectId],
            tenantId: $request->tenant_id,
            applicationId: $request->application_id,
            correlationId: $request->correlation_id,
        );

        return [$request, null];
    }

    /**
     * Convenience entry point for a workflow APPROVAL step.
     *
     * @return array{0: ?ApprovalRequest, 1: ?string}
     */
    public function createRequestForWorkflow(WorkflowInstance $instance, WorkflowStep $step): array
    {
        $code = $step->config['approval_definition_code'] ?? null;
        $definition = ApprovalDefinition::query()->where('definition_code', $code)->first();

        if (! $definition) {
            return [null, 'APPROVAL_NOT_ALLOWED'];
        }

        return $this->createRequest(
            $definition,
            context: $instance->trigger_payload ?? [],
            subjectType: 'WorkflowInstance',
            subjectId: $instance->id,
            workflowInstanceId: $instance->id,
            requestedBy: null,
            correlationId: $instance->correlation_id,
            tenantId: $instance->tenant_id,
        );
    }

    public function decide(ApprovalRequest $request, User $actor, string $decision, ?string $comment = null): ?string
    {
        if (! in_array($decision, ApprovalDecision::DECISIONS, true)) {
            return 'APPROVAL_NOT_ALLOWED';
        }

        if ($request->isExpired()) {
            $request->update(['status' => ApprovalRequest::STATUS_EXPIRED, 'decided_at' => now()]);

            return 'APPROVAL_EXPIRED';
        }

        if (! $request->isOpen()) {
            return 'APPROVAL_NOT_PENDING';
        }

        $definition = $request->definition;
        $isAnyOf = $definition->rule_type === ApprovalDefinition::RULE_ANY_OF;
        $isAllOf = $definition->rule_type === ApprovalDefinition::RULE_ALL_OF;

        // Which steps are currently decidable: SEQUENTIAL = only current_level;
        // ANY_OF/ALL_OF = every still-PENDING step.
        $activeSteps = $isAnyOf || $isAllOf
            ? $request->steps()->where('status', ApprovalRequestStep::STATUS_PENDING)->get()
            : $request->steps()->where('level_order', $request->current_level)->get();

        $step = $activeSteps->first(fn (ApprovalRequestStep $s) => $this->actorMayDecide($s, $actor, $request));

        if (! $step) {
            return 'APPROVAL_NOT_ALLOWED';
        }

        if (! $definition->self_approval_allowed && $request->requested_by && $request->requested_by === $actor->id) {
            return 'SELF_APPROVAL_DENIED';
        }

        if ($step->decision()->exists()) {
            return 'APPROVAL_NOT_PENDING';
        }

        DB::transaction(function () use ($request, $step, $actor, $decision, $comment, $isAnyOf, $isAllOf) {
            ApprovalDecision::create([
                'approval_request_step_id' => $step->id,
                'approval_request_id' => $request->id,
                'decided_by' => $actor->id,
                'decision' => $decision,
                'comment' => $comment,
            ]);

            $stepStatus = match ($decision) {
                ApprovalDecision::DECISION_APPROVE => ApprovalRequestStep::STATUS_APPROVED,
                ApprovalDecision::DECISION_REJECT => ApprovalRequestStep::STATUS_REJECTED,
                ApprovalDecision::DECISION_RETURN => ApprovalRequestStep::STATUS_RETURNED,
            };
            $step->update(['status' => $stepStatus, 'resolved_approver_user_id' => $step->resolved_approver_user_id ?? $actor->id]);

            if ($decision === ApprovalDecision::DECISION_REJECT) {
                $request->update(['status' => ApprovalRequest::STATUS_REJECTED, 'decided_at' => now()]);
                $request->steps()->where('status', ApprovalRequestStep::STATUS_PENDING)->update(['status' => ApprovalRequestStep::STATUS_SKIPPED]);

                return;
            }

            if ($decision === ApprovalDecision::DECISION_RETURN) {
                $request->update(['status' => ApprovalRequest::STATUS_RETURNED, 'decided_at' => now()]);
                $request->steps()->where('status', ApprovalRequestStep::STATUS_PENDING)->update(['status' => ApprovalRequestStep::STATUS_SKIPPED]);

                return;
            }

            // APPROVE
            if ($isAnyOf) {
                $request->update(['status' => ApprovalRequest::STATUS_APPROVED, 'decided_at' => now()]);
                $request->steps()->where('status', ApprovalRequestStep::STATUS_PENDING)->update(['status' => ApprovalRequestStep::STATUS_SKIPPED]);

                return;
            }

            if ($isAllOf) {
                $stillPending = $request->steps()->where('status', ApprovalRequestStep::STATUS_PENDING)->exists();
                $request->update(['status' => $stillPending ? ApprovalRequest::STATUS_IN_PROGRESS : ApprovalRequest::STATUS_APPROVED, 'decided_at' => $stillPending ? null : now()]);

                return;
            }

            // SEQUENTIAL
            $nextLevel = $request->steps()->where('level_order', '>', $step->level_order)->min('level_order');
            if ($nextLevel) {
                $request->update(['status' => ApprovalRequest::STATUS_IN_PROGRESS, 'current_level' => $nextLevel]);
            } else {
                $request->update(['status' => ApprovalRequest::STATUS_APPROVED, 'decided_at' => now()]);
            }
        });

        $this->audit->record(
            'approval.decided',
            actor: $actor,
            resourceType: 'ApprovalRequest',
            resourceId: $request->id,
            newValue: ['decision' => $decision, 'level_order' => $step->level_order],
            tenantId: $request->tenant_id,
            applicationId: $request->application_id,
            correlationId: $request->correlation_id,
        );

        $request->refresh();

        if (in_array($request->status, ApprovalRequest::TERMINAL_STATUSES, true) && $request->workflow_instance_id) {
            $this->resumeWorkflowIfLinked($request);
        }

        return null;
    }

    private function resumeWorkflowIfLinked(ApprovalRequest $request): void
    {
        $instanceStep = WorkflowInstanceStep::query()
            ->where('workflow_instance_id', $request->workflow_instance_id)
            ->where('status', WorkflowInstanceStep::STATUS_WAITING)
            ->whereHas('step', fn ($q) => $q->where('step_type', WorkflowStep::TYPE_APPROVAL))
            ->latest('created_at')
            ->first();

        if ($instanceStep) {
            app(WorkflowExecutionService::class)->resumeFromApproval($instanceStep, $request->status);
        }
    }

    public function createDelegation(User $delegator, User $delegate, ?string $approvalDefinitionId, ?string $tenantId, ?\DateTimeInterface $startsAt, ?\DateTimeInterface $endsAt): ApprovalDelegation
    {
        $delegation = ApprovalDelegation::create([
            'tenant_id' => $tenantId,
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'approval_definition_id' => $approvalDefinitionId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => ApprovalDelegation::STATUS_ACTIVE,
        ]);

        $this->audit->record('approval.delegation.created', actor: $delegator, resourceType: 'ApprovalDelegation', resourceId: $delegation->id, newValue: ['delegate_user_id' => $delegate->id], tenantId: $tenantId);

        return $delegation;
    }

    public function revokeDelegation(ApprovalDelegation $delegation, User $actor): void
    {
        $delegation->update(['status' => ApprovalDelegation::STATUS_REVOKED, 'revoked_at' => now()]);

        $this->audit->record('approval.delegation.revoked', actor: $actor, resourceType: 'ApprovalDelegation', resourceId: $delegation->id, tenantId: $delegation->tenant_id);
    }

    /**
     * True when $actor may decide $step: either the pre-resolved approver
     * (USER/DYNAMIC), a holder of the required role (ROLE/TENANT_ROLE/
     * APPLICATION_ROLE), or an active delegate of either.
     */
    private function actorMayDecide(ApprovalRequestStep $step, User $actor, ApprovalRequest $request): bool
    {
        $level = $step->level;

        if (in_array($level->approver_type, [ApprovalLevel::APPROVER_USER, ApprovalLevel::APPROVER_DYNAMIC], true)) {
            if ($step->resolved_approver_user_id === $actor->id) {
                return true;
            }

            return $this->isActiveDelegateFor($step->resolved_approver_user_id, $actor, $request);
        }

        $roleCode = $level->approver_reference;
        if (! $roleCode) {
            return false;
        }

        $tenantScope = in_array($level->approver_type, [ApprovalLevel::APPROVER_TENANT_ROLE, ApprovalLevel::APPROVER_APPLICATION_ROLE], true)
            ? $request->tenant_id
            : null;

        return $this->userHoldsRole($actor, $roleCode, $tenantScope);
    }

    private function isActiveDelegateFor(?string $delegatorUserId, User $actor, ApprovalRequest $request): bool
    {
        if (! $delegatorUserId) {
            return false;
        }

        return ApprovalDelegation::query()
            ->where('delegator_user_id', $delegatorUserId)
            ->where('delegate_user_id', $actor->id)
            ->where('status', ApprovalDelegation::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('approval_definition_id')->orWhere('approval_definition_id', $request->approval_definition_id))
            ->get()
            ->contains(fn (ApprovalDelegation $d) => $d->isActiveNow());
    }

    private function userHoldsRole(User $user, string $roleCode, ?string $tenantId): bool
    {
        $query = $user->userRoles()->whereHas('role', fn ($q) => $q->where('code', $roleCode));

        if ($tenantId) {
            $query->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
        } else {
            $query->whereNull('tenant_id');
        }

        return $query->exists();
    }

    /**
     * Resolves USER/DYNAMIC approver types to a concrete user_id at
     * request-creation time. ROLE/TENANT_ROLE/APPLICATION_ROLE are left
     * unresolved (null) - membership is checked live at decision time
     * since "who holds this role" can change between request and decision.
     */
    private function resolveStaticApprover(ApprovalLevel $level, array $context): ?string
    {
        if ($level->approver_type === ApprovalLevel::APPROVER_USER) {
            return $level->approver_reference;
        }

        if ($level->approver_type === ApprovalLevel::APPROVER_DYNAMIC) {
            $path = $level->approver_reference ?? ($level->condition['field'] ?? null);

            return $path ? $this->resolveContextPath($path, $context) : null;
        }

        return null;
    }

    private function resolveContextPath(string $path, array $context): ?string
    {
        $value = $context;
        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return is_string($value) ? $value : null;
    }
}
