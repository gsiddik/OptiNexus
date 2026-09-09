<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\BillingRunRequest;
use App\Http\Resources\V1\BillingResource;
use App\Models\Billing;
use App\Models\BillingAdjustment;
use App\Models\Subscription;
use App\Services\AuditService;
use App\Services\Commercial\BillingCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BillingController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly BillingCalculationService $calculation,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Billing::query();

        foreach (['tenant_id', 'customer_id', 'subscription_id', 'status'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, BillingResource::class);
    }

    public function show(Billing $billing): JsonResponse
    {
        return $this->ok(new BillingResource($billing->load(['items', 'adjustments'])));
    }

    public function run(BillingRunRequest $request): JsonResponse
    {
        $subscriptions = $this->resolveSubscriptions($request);

        if ($subscriptions->isEmpty()) {
            return $this->fail('RESOURCE_NOT_FOUND', 'No matching active subscriptions were found to bill.', 404);
        }

        $results = [];
        foreach ($subscriptions as $subscription) {
            $start = $request->input('period_start') ? Carbon::parse($request->input('period_start')) : $subscription->current_period_start;
            $end = $request->input('period_end') ? Carbon::parse($request->input('period_end')) : $subscription->current_period_end;

            if (! $start || ! $end) {
                continue;
            }

            [$billing, $error] = $this->calculation->calculate($subscription, $start, $end);

            if ($error) {
                $results[] = ['subscription_id' => $subscription->id, 'error' => $error];

                continue;
            }

            $this->audit->record('billing.calculated', $request, resourceType: 'Billing', resourceId: $billing->id, newValue: ['total' => $billing->total], tenantId: $billing->tenant_id, customerId: $billing->customer_id);

            $results[] = new BillingResource($billing);
        }

        return $this->created(['results' => $results]);
    }

    public function recalculate(Request $request, Billing $billing): JsonResponse
    {
        [$updated, $error] = $this->calculation->calculate($billing->subscription, Carbon::parse($billing->period_start), Carbon::parse($billing->period_end));

        if ($error) {
            return $this->fail($error, 'This billing is already finalized and can no longer be recalculated.', 409);
        }

        $this->audit->record('billing.recalculated', $request, resourceType: 'Billing', resourceId: $billing->id, newValue: ['total' => $updated->total], tenantId: $billing->tenant_id);

        return $this->ok(new BillingResource($updated->load(['items', 'adjustments'])));
    }

    public function review(Request $request, Billing $billing): JsonResponse
    {
        if (! in_array($billing->status, [Billing::STATUS_DRAFT, Billing::STATUS_CALCULATED], true)) {
            return $this->fail('INVALID_STATE_TRANSITION', "Cannot review a billing in status [{$billing->status}].", 409);
        }

        $billing->update(['status' => Billing::STATUS_REVIEWED]);

        $this->audit->record('billing.reviewed', $request, resourceType: 'Billing', resourceId: $billing->id, tenantId: $billing->tenant_id);

        return $this->ok(new BillingResource($billing));
    }

    public function finalize(Request $request, Billing $billing): JsonResponse
    {
        if (! in_array($billing->status, [Billing::STATUS_CALCULATED, Billing::STATUS_REVIEWED], true)) {
            return $this->fail('INVALID_STATE_TRANSITION', "Cannot finalize a billing in status [{$billing->status}].", 409);
        }

        $billing->update(['status' => Billing::STATUS_FINALIZED, 'finalized_at' => now()]);

        $this->audit->record('billing.finalized', $request, resourceType: 'Billing', resourceId: $billing->id, newValue: ['total' => $billing->total], tenantId: $billing->tenant_id, customerId: $billing->customer_id);

        return $this->ok(new BillingResource($billing->load('items')));
    }

    public function cancel(Request $request, Billing $billing): JsonResponse
    {
        if ($billing->isFinalized()) {
            return $this->fail('BILLING_ALREADY_FINALIZED', 'A finalized billing cannot be cancelled.', 409);
        }

        $billing->update(['status' => Billing::STATUS_CANCELLED]);

        $this->audit->record('billing.cancelled', $request, resourceType: 'Billing', resourceId: $billing->id, tenantId: $billing->tenant_id);

        return $this->ok(new BillingResource($billing));
    }

    public function addAdjustment(Request $request, Billing $billing): JsonResponse
    {
        if ($billing->status === Billing::STATUS_CANCELLED) {
            return $this->fail('CONFLICT', 'Cannot adjust a cancelled billing.', 409);
        }

        $validated = $request->validate([
            'adjustment_type' => ['required', 'string', 'in:'.implode(',', BillingAdjustment::TYPES)],
            'reason' => ['required', 'string', 'max:1000'],
            'amount' => ['required', 'numeric'],
        ]);

        $adjustment = $billing->adjustments()->create([
            ...$validated,
            'status' => BillingAdjustment::STATUS_APPLIED,
            'created_by' => $request->user()?->id,
        ]);

        $adjustmentTotal = \App\Support\Money::sum($billing->adjustments()->pluck('amount')->all());
        $newTotal = \App\Support\Money::add(\App\Support\Money::add($billing->subtotal, $billing->tax_total), $adjustmentTotal);
        $billing->update(['adjustment_total' => $adjustmentTotal, 'total' => $newTotal]);

        $this->audit->record('billing.adjusted', $request, resourceType: 'Billing', resourceId: $billing->id, newValue: $adjustment->toArray(), tenantId: $billing->tenant_id);

        return $this->created(new BillingResource($billing->load(['items', 'adjustments'])));
    }

    private function resolveSubscriptions(BillingRunRequest $request)
    {
        if ($request->input('subscription_id')) {
            return Subscription::query()->where('id', $request->input('subscription_id'))->get();
        }

        $query = Subscription::query()->whereIn('status', Subscription::ACTIVE_LIKE_STATUSES);

        if ($request->input('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }

        return $query->get();
    }
}
