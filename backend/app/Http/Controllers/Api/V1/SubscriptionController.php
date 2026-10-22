<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreSubscriptionRequest;
use App\Http\Resources\V1\PriceResource;
use App\Http\Resources\V1\SubscriptionResource;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\AuditService;
use App\Services\Commercial\SubscriptionLifecycleService;
use App\Services\Commercial\SubscriptionPriceSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    use ApiResponses;

    private const ERROR_STATUS = [
        'PLAN_NOT_ACTIVE' => 422,
        'PRODUCT_NOT_ACTIVE' => 422,
        'TENANT_CUSTOMER_MISMATCH' => 422,
        'PLAN_HAS_NO_TRIAL' => 422,
        'INVALID_SUBSCRIPTION_TRANSITION' => 409,
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly SubscriptionLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Subscription::query()->with(['customer', 'tenant', 'product', 'plan', 'items.plan', 'items.addon'])->with('items');

        foreach (['tenant_id', 'customer_id', 'plan_id', 'status'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 15));

        return $this->paginated($paginator, SubscriptionResource::class);
    }

    public function customerOptions(): JsonResponse
    {
        return $this->ok(Customer::query()->orderBy('legal_name')->get(['id', 'legal_name', 'customer_code']));
    }

    public function tenantOptions(Request $request): JsonResponse
    {
        $data = $request->validate(['customer_id' => ['required', 'uuid', 'exists:customers,id']]);

        return $this->ok(Tenant::query()->where('customer_id', $data['customer_id'])->orderBy('name')->get(['id', 'customer_id', 'name', 'tenant_code']));
    }

    public function priceOptions(Request $request, SubscriptionPriceSelection $selection): JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'uuid', 'exists:tenants,id']]);

        return $this->ok(PriceResource::collection($selection->options(Tenant::findOrFail($data['tenant_id']))));
    }

    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! empty($data['idempotency_key'])) {
            $existing = Subscription::query()
                ->where('tenant_id', $data['tenant_id'])
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existing) {
                return $this->ok(new SubscriptionResource($existing->load('items')));
            }
        }

        $customer = Customer::findOrFail($data['customer_id']);
        $tenant = Tenant::findOrFail($data['tenant_id']);
        $plan = Plan::findOrFail($data['plan_id']);

        [$subscription, $error] = $this->lifecycle->create($customer, $tenant, $plan, $data);

        if ($error) {
            return $this->errorResponse($error);
        }

        $this->audit->record('subscription.created', $request, resourceType: 'Subscription', resourceId: $subscription->id, newValue: $subscription->toArray(), tenantId: $tenant->id, customerId: $customer->id);

        return $this->created(new SubscriptionResource($subscription->load('items')));
    }

    public function show(Subscription $subscription): JsonResponse
    {
        return $this->ok(new SubscriptionResource($subscription->load('items')));
    }

    /**
     * Deliberately narrow: status, plan, and period fields are never
     * editable here - only explicit transition endpoints may change them.
     */
    public function update(Request $request, Subscription $subscription): JsonResponse
    {
        $validated = $request->validate([
            'auto_renew' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        if (array_key_exists('metadata', $validated)) {
            $validated['metadata'] = [...($validated['metadata'] ?? []), 'application_ids' => $subscription->metadata['application_ids'] ?? null];
        }
        $old = $subscription->only(['auto_renew', 'metadata']);
        $subscription->update($validated);

        $this->audit->record('subscription.updated', $request, resourceType: 'Subscription', resourceId: $subscription->id, oldValue: $old, newValue: $subscription->only(['auto_renew', 'metadata']), tenantId: $subscription->tenant_id);

        return $this->ok(new SubscriptionResource($subscription->load('items')));
    }

    public function activate(Request $request, Subscription $subscription): JsonResponse
    {
        return $this->applyTransition($request, $subscription, 'activate', 'subscription.activated');
    }

    public function startTrial(Request $request, Subscription $subscription): JsonResponse
    {
        return $this->applyTransition($request, $subscription, 'startTrial', 'subscription.trial_started');
    }

    public function upgrade(Request $request, Subscription $subscription): JsonResponse
    {
        return $this->applyPlanChange($request, $subscription, 'subscription.upgraded');
    }

    public function downgrade(Request $request, Subscription $subscription): JsonResponse
    {
        return $this->applyPlanChange($request, $subscription, 'subscription.downgraded');
    }

    public function renew(Request $request, Subscription $subscription): JsonResponse
    {
        return $this->applyTransition($request, $subscription, 'renew', 'subscription.renewed');
    }

    public function cancel(Request $request, Subscription $subscription): JsonResponse
    {
        return $this->applyTransition($request, $subscription, 'cancel', 'subscription.cancelled');
    }

    public function suspend(Request $request, Subscription $subscription): JsonResponse
    {
        return $this->applyTransition($request, $subscription, 'suspend', 'subscription.suspended');
    }

    public function reactivate(Request $request, Subscription $subscription): JsonResponse
    {
        return $this->applyTransition($request, $subscription, 'reactivate', 'subscription.reactivated');
    }

    public function expire(Request $request, Subscription $subscription): JsonResponse
    {
        return $this->applyTransition($request, $subscription, 'expire', 'subscription.expired');
    }

    public function terminate(Request $request, Subscription $subscription): JsonResponse
    {
        return $this->applyTransition($request, $subscription, 'terminate', 'subscription.terminated');
    }

    private function applyTransition(Request $request, Subscription $subscription, string $method, string $auditAction): JsonResponse
    {
        $old = $subscription->status;
        $error = DB::transaction(function () use ($subscription, $method) {
            Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $subscription->refresh();

            return $this->lifecycle->{$method}($subscription);
        });

        if ($error) {
            return $this->errorResponse($error);
        }

        $subscription->refresh();

        $this->audit->record($auditAction, $request, resourceType: 'Subscription', resourceId: $subscription->id, oldValue: ['status' => $old], newValue: ['status' => $subscription->status], tenantId: $subscription->tenant_id, customerId: $subscription->customer_id);

        return $this->ok(new SubscriptionResource($subscription->load('items')));
    }

    private function applyPlanChange(Request $request, Subscription $subscription, string $auditAction): JsonResponse
    {
        $validated = $request->validate(['plan_id' => ['required', 'uuid', 'exists:plans,id']]);
        $newPlan = Plan::findOrFail($validated['plan_id']);
        $oldPlanId = $subscription->plan_id;

        $error = DB::transaction(function () use ($subscription, $newPlan) {
            Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $subscription->refresh();

            return $this->lifecycle->changePlan($subscription, $newPlan);
        });

        if ($error) {
            return $this->errorResponse($error);
        }

        $subscription->refresh();

        $this->audit->record($auditAction, $request, resourceType: 'Subscription', resourceId: $subscription->id, oldValue: ['plan_id' => $oldPlanId], newValue: ['plan_id' => $newPlan->id], tenantId: $subscription->tenant_id, customerId: $subscription->customer_id);

        return $this->ok(new SubscriptionResource($subscription->load('items')));
    }

    private function errorResponse(string $code): JsonResponse
    {
        $status = self::ERROR_STATUS[$code] ?? 422;

        return $this->fail($code, $this->humanize($code), $status);
    }

    private function humanize(string $code): string
    {
        return match ($code) {
            'PLAN_NOT_ACTIVE' => 'The plan is not active and cannot be subscribed to.',
            'PRODUCT_NOT_ACTIVE' => 'The product is not active.',
            'TENANT_CUSTOMER_MISMATCH' => 'The tenant does not belong to the given customer.',
            'PLAN_HAS_NO_TRIAL' => 'This plan has no trial configuration.',
            'INVALID_SUBSCRIPTION_TRANSITION' => 'This transition is not allowed from the subscription\'s current state.',
            default => 'The request could not be completed.',
        };
    }
}
