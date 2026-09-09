<?php

namespace App\Services\Access;

use App\Models\Application;
use App\Models\FeatureFlag;
use App\Models\Policy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\Commercial\EntitlementService;
use App\Services\FeatureFlag\FeatureFlagEvaluationService;
use App\Services\Policy\PolicyEvaluationService;

/**
 * Aggregates the combined access model - Authentication -> Tenant/App
 * Context -> Subscription/Entitlement -> RBAC Permission -> Policy ->
 * Feature Flag -> ALLOW/DENY - into one read-only decision, reusing each
 * domain's own existing service rather than re-implementing any of them.
 * All four sub-checks are always computed and reported (never short-
 * circuited) so a caller can see exactly which one would block them;
 * `allowed` is simply their conjunction. Never exposes matched policy
 * detail or role/permission internals - only booleans and a reason code.
 */
class AccessEvaluationService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly EntitlementService $entitlement,
        private readonly PolicyEvaluationService $policy,
        private readonly FeatureFlagEvaluationService $featureFlag,
    ) {}

    /**
     * @param  array{user_id: string, tenant_id?: ?string, application_code: string, permission: string, entitlement_key?: ?string, feature_flag_key?: ?string, action?: ?string, resource_context?: array}  $input
     */
    public function evaluate(array $input): array
    {
        $user = User::query()->find($input['user_id']);
        $application = Application::query()->where('application_code', $input['application_code'])->first();

        if (! $user || ! $application) {
            return $this->deny('RESOURCE_NOT_FOUND');
        }

        $tenant = ! empty($input['tenant_id']) ? Tenant::query()->find($input['tenant_id']) : null;

        $checks = ['entitlement' => true, 'permission' => false, 'policy' => true, 'feature_flag' => true];
        $reasonCode = null;

        $authResult = $this->authorization->check($user, $tenant, $application, $input['permission']);
        $checks['permission'] = $authResult['allowed'];
        if (! $authResult['allowed']) {
            $reasonCode ??= $authResult['reason'];
        }

        if (! empty($input['entitlement_key'])) {
            if (! $tenant) {
                $checks['entitlement'] = false;
                $reasonCode ??= 'ENTITLEMENT_TENANT_REQUIRED';
            } else {
                $entitlementResult = $this->entitlement->check($tenant, $input['application_code'], $input['entitlement_key']);
                $checks['entitlement'] = $entitlementResult['allowed'];
                if (! $entitlementResult['allowed']) {
                    $reasonCode ??= 'ENTITLEMENT_NOT_ACTIVE';
                }
            }
        }

        $policyResult = $this->policy->evaluate([
            'policy_type' => Policy::TYPE_AUTHORIZATION,
            'tenant_id' => $tenant?->id,
            'application_id' => $application->id,
            'permission' => $input['permission'],
            'action' => $input['action'] ?? null,
            'actor' => ['user_id' => $user->id],
            'tenant' => ['id' => $tenant?->id],
            'application' => ['id' => $application->id],
            'resource' => $input['resource_context'] ?? [],
        ]);
        $checks['policy'] = $policyResult['decision'] !== 'DENY';
        if ($policyResult['decision'] === 'DENY') {
            $reasonCode ??= $policyResult['reason_code'];
        }

        if (! empty($input['feature_flag_key'])) {
            $flag = FeatureFlag::query()->where('flag_key', $input['feature_flag_key'])->first();
            if (! $flag) {
                $checks['feature_flag'] = false;
                $reasonCode ??= 'FEATURE_FLAG_NOT_FOUND';
            } else {
                $flagResult = $this->featureFlag->evaluate($flag, $tenant?->id, $user->id);
                $checks['feature_flag'] = $flagResult['enabled'];
                if (! $flagResult['enabled']) {
                    $reasonCode ??= 'FEATURE_FLAG_SCOPE_DENIED';
                }
            }
        }

        $allowed = $checks['entitlement'] && $checks['permission'] && $checks['policy'] && $checks['feature_flag'];

        return ['allowed' => $allowed, 'checks' => $checks, 'reason_code' => $allowed ? null : $reasonCode];
    }

    private function deny(string $reasonCode): array
    {
        return [
            'allowed' => false,
            'checks' => ['entitlement' => false, 'permission' => false, 'policy' => false, 'feature_flag' => false],
            'reason_code' => $reasonCode,
        ];
    }
}
