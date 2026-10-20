<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Minimal active relation choices, authorized by the action that consumes them. */
class FormOptionController extends Controller
{
    use ApiResponses;

    public function __invoke(Request $request, string $kind, AuthorizationService $authorization): JsonResponse
    {
        [$model, $permissions, $columns] = match ($kind) {
            'plans' => [Plan::class, ['cgo.plan.view', 'cgo.subscription.create', 'cgo.subscription.upgrade', 'cgo.subscription.downgrade', 'cgo.pricing.create', 'cgo.pricing.simulate'], ['id', 'name', 'status', 'plan_code']],
            'addons' => [Addon::class, ['cgo.addon.view', 'cgo.pricing.create', 'cgo.pricing.simulate'], ['id', 'name', 'status', 'addon_code']],
            'tenants' => [Tenant::class, ['cgo.tenant.view', 'cgo.user.tenant.assign', 'cgo.pricing.simulate', 'cgo.billing.create'], ['id', 'name', 'status']],
            'subscriptions' => [Subscription::class, ['cgo.billing.create'], ['id', 'subscription_number', 'tenant_id', 'status']],
            default => [null, [], []],
        };
        if (! $model) {
            return $this->fail('RESOURCE_NOT_FOUND', 'Unknown option type.', 404);
        }
        if (! collect($permissions)->contains(fn ($permission) => $authorization->userHasPermission($request->user(), $permission))) {
            return $this->fail('UNAUTHORIZED', 'You cannot use these form options.', 403);
        }

        return $this->ok($model::query()->where('status', 'ACTIVE')->orderBy($kind === 'subscriptions' ? 'subscription_number' : 'name')->get($columns));
    }
}
