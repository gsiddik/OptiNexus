<?php

namespace App\Services\Policy;

use App\Models\Policy;
use Illuminate\Support\Collection;

/**
 * Central Policy Evaluation Service. Resolves ACTIVE, currently-effective
 * policies scoped to (tenant, application, policy_type), evaluates each
 * against a safe declarative context, and combines matched effects with
 * explicit-deny precedence: any matched DENY wins over any matched ALLOW,
 * regardless of priority order. Priority only orders which policies are
 * considered/reported first (relevant for MATCH-effect routing policies,
 * where the caller wants the single highest-priority match).
 *
 * This never grants access on its own: NEUTRAL (no policy matched) means
 * "policy has no opinion" - the caller's own RBAC/entitlement decision
 * stands. Only an explicit ALLOW/DENY policy changes the outcome, and per
 * the required precedence, DENY always wins.
 */
class PolicyEvaluationService
{
    public function __construct(private readonly PolicyConditionEvaluator $evaluator) {}

    /**
     * @param  array{policy_type?: ?string, tenant_id?: ?string, application_id?: ?string, permission?: ?string, action?: ?string, actor?: array, resource?: array, context?: array}  $input
     * @return array{decision: string, effect: string, reason_code: string, matched_policies: Collection<int, Policy>}
     */
    public function evaluate(array $input): array
    {
        $policies = $this->candidatePolicies($input['policy_type'] ?? null, $input['tenant_id'] ?? null, $input['application_id'] ?? null);

        $context = [
            'actor' => $input['actor'] ?? [],
            'tenant' => $input['tenant'] ?? [],
            'application' => $input['application'] ?? [],
            'resource' => $input['resource'] ?? [],
            'action' => $input['action'] ?? null,
            'permission' => $input['permission'] ?? null,
            'context' => $input['context'] ?? [],
        ];

        $matched = $policies->filter(function (Policy $policy) use ($context) {
            try {
                return $this->evaluator->evaluate($policy->condition_definition, $context);
            } catch (InvalidPolicyConditionException) {
                // A stored condition should already be validated at save
                // time; never let a malformed one crash evaluation for
                // every other policy - just treat it as non-matching.
                return false;
            }
        })->values();

        $hasDeny = $matched->contains(fn (Policy $p) => $p->effect === Policy::EFFECT_DENY);
        $hasAllow = $matched->contains(fn (Policy $p) => $p->effect === Policy::EFFECT_ALLOW);

        $decision = $hasDeny ? 'DENY' : ($hasAllow ? 'ALLOW' : 'NEUTRAL');
        $reasonCode = match (true) {
            $hasDeny => 'POLICY_EXPLICIT_DENY',
            $hasAllow => 'POLICY_ALLOW',
            default => 'POLICY_NO_MATCH',
        };

        return [
            'decision' => $decision,
            'effect' => $decision,
            'reason_code' => $reasonCode,
            'matched_policies' => $matched,
        ];
    }

    /**
     * @return Collection<int, Policy>
     */
    private function candidatePolicies(?string $policyType, ?string $tenantId, ?string $applicationId): Collection
    {
        $query = Policy::query()->where('status', Policy::STATUS_ACTIVE);

        if ($policyType) {
            $query->where('policy_type', $policyType);
        }

        $query->where(fn ($q) => $q->whereNull('tenant_id')->when($tenantId, fn ($q2) => $q2->orWhere('tenant_id', $tenantId)));
        $query->where(fn ($q) => $q->whereNull('application_id')->when($applicationId, fn ($q2) => $q2->orWhere('application_id', $applicationId)));

        return $query->orderByDesc('priority')->orderBy('created_at')->get()
            ->filter(fn (Policy $p) => $p->isEffectiveAt())
            ->values();
    }
}
