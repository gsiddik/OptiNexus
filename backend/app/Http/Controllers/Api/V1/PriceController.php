<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StorePriceRequest;
use App\Http\Resources\V1\PriceResource;
use App\Models\Price;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PriceController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Price::query()->with('tiers');

        foreach (['plan_id', 'addon_id', 'tenant_id', 'status', 'price_type'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('effective_from')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, PriceResource::class);
    }

    public function store(StorePriceRequest $request): JsonResponse
    {
        return $this->createPrice($request);
    }

    public function storeOverride(StorePriceRequest $request): JsonResponse
    {
        if (! $request->input('tenant_id')) {
            return $this->fail('VALIDATION_ERROR', 'tenant_id is required to create a custom tenant pricing override.', 422);
        }

        return $this->createPrice($request);
    }

    private function createPrice(StorePriceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $isOverride = ! empty($data['tenant_id']);

        $price = DB::transaction(function () use ($data, $isOverride, $request) {
            $price = Price::create([
                ...collect($data)->except('tiers')->all(),
                'status' => Price::STATUS_ACTIVE,
                'approval_status' => $isOverride ? Price::APPROVAL_PENDING : Price::APPROVAL_APPROVED,
                'effective_from' => $data['effective_from'] ?? now(),
                'created_by' => $request->user()?->id,
                'approved_by' => $isOverride ? null : $request->user()?->id,
            ]);

            if ($price->price_type === Price::TYPE_TIERED) {
                foreach (collect($data['tiers'])->sortBy('from_quantity')->values() as $i => $tier) {
                    $price->tiers()->create([
                        'tier_order' => $i + 1,
                        'from_quantity' => $tier['from_quantity'],
                        'to_quantity' => $tier['to_quantity'] ?? null,
                        'unit_amount' => $tier['unit_amount'],
                        'flat_amount' => $tier['flat_amount'] ?? null,
                    ]);
                }
            }

            return $price;
        });

        $this->audit->record(
            $isOverride ? 'pricing.override_requested' : 'pricing.created',
            $request,
            resourceType: 'Price',
            resourceId: $price->id,
            newValue: $price->toArray(),
            tenantId: $price->tenant_id,
        );

        return $this->created(new PriceResource($price->load('tiers')));
    }

    public function show(Price $price): JsonResponse
    {
        return $this->ok(new PriceResource($price->load('tiers')));
    }

    public function update(Request $request, Price $price): JsonResponse
    {
        // Prices are versioned, not mutated: only the closing date and
        // free-form metadata may change after creation. Amount/type
        // corrections must be issued as a new, separately effective price.
        $validated = $request->validate([
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'metadata' => ['nullable', 'array'],
        ]);

        $old = $price->toArray();
        $price->update($validated);

        $this->audit->record('pricing.updated', $request, resourceType: 'Price', resourceId: $price->id, oldValue: $old, newValue: $price->toArray(), tenantId: $price->tenant_id);

        return $this->ok(new PriceResource($price->load('tiers')));
    }

    public function activate(Request $request, Price $price): JsonResponse
    {
        if ($price->approval_status === Price::APPROVAL_APPROVED) {
            return $this->fail('CONFLICT', 'This price is already approved.', 409);
        }

        if ($price->created_by && $price->created_by === $request->user()?->id) {
            return $this->fail('UNAUTHORIZED', 'The approver must differ from the price creator (segregation of duties).', 403);
        }

        $price->update([
            'approval_status' => Price::APPROVAL_APPROVED,
            'approved_by' => $request->user()?->id,
        ]);

        $this->audit->record('pricing.override_approved', $request, resourceType: 'Price', resourceId: $price->id, newValue: ['approval_status' => Price::APPROVAL_APPROVED], tenantId: $price->tenant_id);

        return $this->ok(new PriceResource($price));
    }

    public function retire(Request $request, Price $price): JsonResponse
    {
        if ($response = $this->guardTransition($price, Price::STATUS_RETIRED, [Price::STATUS_ACTIVE])) {
            return $response;
        }

        $price->update(['status' => Price::STATUS_RETIRED, 'effective_until' => $price->effective_until ?? now()]);

        $this->audit->record('pricing.retired', $request, resourceType: 'Price', resourceId: $price->id, newValue: ['status' => Price::STATUS_RETIRED], tenantId: $price->tenant_id);

        return $this->ok(new PriceResource($price));
    }
}
