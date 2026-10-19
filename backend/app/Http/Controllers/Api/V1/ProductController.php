<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreProductRequest;
use App\Http\Requests\V1\UpdateProductRequest;
use App\Http\Resources\V1\ApplicationResource;
use App\Http\Resources\V1\CapabilityResource;
use App\Http\Resources\V1\ProductResource;
use App\Models\Application;
use App\Models\Capability;
use App\Models\Product;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Product::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")->orWhere('product_code', 'ilike', "%{$search}%");
            });
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 15));

        return $this->paginated($paginator, ProductResource::class);
    }

    public function applicationOptions(): JsonResponse
    {
        return $this->ok(Application::query()->orderBy('name')->get(['id', 'name', 'application_code']));
    }

    public function capabilityOptions(Product $product): JsonResponse
    {
        $applications = $product->applications()->with(['capabilities' => fn ($query) => $query->orderBy('sort_order')->orderBy('name')])->orderBy('name')->get();

        return $this->ok($applications->map(fn (Application $application) => [
            'application' => $application->only(['id', 'name', 'application_code']),
            'capabilities' => CapabilityResource::collection($application->capabilities),
        ]));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create([...$request->validated(), 'product_code' => 'PRD-'.Str::ulid(), 'status' => Product::STATUS_DRAFT]);

        $this->audit->record('product.created', $request, resourceType: 'Product', resourceId: $product->id, newValue: $product->toArray());

        return $this->created(new ProductResource($product));
    }

    public function show(Product $product): JsonResponse
    {
        return $this->ok(new ProductResource($product));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $old = $product->toArray();
        $product->update($request->validated());

        $this->audit->record('product.updated', $request, resourceType: 'Product', resourceId: $product->id, oldValue: $old, newValue: $product->toArray());

        return $this->ok(new ProductResource($product));
    }

    public function activate(Request $request, Product $product): JsonResponse
    {
        return $this->transitionTo($request, $product, Product::STATUS_ACTIVE, [Product::STATUS_DRAFT, Product::STATUS_INACTIVE]);
    }

    public function deactivate(Request $request, Product $product): JsonResponse
    {
        return $this->transitionTo($request, $product, Product::STATUS_INACTIVE, [Product::STATUS_ACTIVE]);
    }

    public function retire(Request $request, Product $product): JsonResponse
    {
        return $this->transitionTo($request, $product, Product::STATUS_RETIRED, [Product::STATUS_ACTIVE, Product::STATUS_INACTIVE]);
    }

    public function applications(Product $product): JsonResponse
    {
        return $this->ok(ApplicationResource::collection($product->applications));
    }

    public function attachApplication(Request $request, Product $product, Application $application): JsonResponse
    {
        return DB::transaction(function () use ($request, $product, $application) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($product->applications()->where('applications.id', $application->id)->exists()) {
                return $this->fail('DUPLICATE_RESOURCE', 'This application is already assigned to the product.', 409);
            }

            $product->applications()->attach($application->id, ['id' => (string) Str::uuid()]);

            $this->audit->record('product.application_assigned', $request, resourceType: 'Product', resourceId: $product->id, newValue: ['application_id' => $application->id], applicationId: $application->id);

            return $this->created(ApplicationResource::collection($product->applications()->get()));
        });
    }

    public function detachApplication(Request $request, Product $product, Application $application): JsonResponse
    {
        DB::transaction(function () use ($request, $product, $application) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $capabilityIds = Capability::withTrashed()->where('application_id', $application->id)->pluck('id');
            $product->capabilities()->detach($capabilityIds);
            $product->applications()->detach($application->id);

            $this->audit->record('product.application_revoked', $request, resourceType: 'Product', resourceId: $product->id, oldValue: ['application_id' => $application->id], applicationId: $application->id);
        });

        return $this->ok(null);
    }

    public function capabilities(Product $product): JsonResponse
    {
        return $this->ok(CapabilityResource::collection($product->capabilities));
    }

    public function syncCapabilities(Request $request, Product $product, AuthorizationService $authorization): JsonResponse
    {
        return DB::transaction(function () use ($request, $product, $authorization) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $applicationIds = $product->applications()->pluck('applications.id')->all();
            $data = $request->validate([
                'capability_ids' => ['present', 'array'],
                'capability_ids.*' => ['required', 'uuid', 'distinct', Rule::exists('capabilities', 'id')->whereIn('application_id', $applicationIds)->whereNull('deleted_at')],
            ]);
            $previous = $product->capabilities()->pluck('capabilities.id')->all();
            $selected = $data['capability_ids'];
            foreach ([
                'cgo.product.capability.assign' => array_diff($selected, $previous),
                'cgo.product.capability.revoke' => array_diff($previous, $selected),
            ] as $permission => $changes) {
                abort_if($changes && ! $authorization->userHasPermission($request->user(), $permission), 403, "You do not have the [{$permission}] permission required for this action.");
            }

            $pivotData = [];
            foreach ($selected as $id) {
                $pivotData[$id] = in_array($id, $previous, true) ? [] : ['id' => (string) Str::uuid()];
            }
            $product->capabilities()->sync($pivotData);
            $this->audit->record('product.capabilities_updated', $request, resourceType: 'Product', resourceId: $product->id, oldValue: ['capability_ids' => $previous], newValue: ['capability_ids' => $selected]);

            return $this->ok(CapabilityResource::collection($product->capabilities()->get()));
        });
    }

    public function attachCapability(Request $request, Product $product, Capability $capability): JsonResponse
    {
        return DB::transaction(function () use ($request, $product, $capability) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if (! $product->applications()->where('applications.id', $capability->application_id)->exists()) {
                return $this->fail('VALIDATION_ERROR', 'Attach the capability application to this product first.', 422);
            }

            if ($product->capabilities()->where('capabilities.id', $capability->id)->exists()) {
                return $this->fail('DUPLICATE_RESOURCE', 'This capability is already assigned to the product.', 409);
            }

            $product->capabilities()->attach($capability->id, ['id' => (string) Str::uuid()]);

            $this->audit->record('product.capability_assigned', $request, resourceType: 'Product', resourceId: $product->id, newValue: ['capability_id' => $capability->id]);

            return $this->created(CapabilityResource::collection($product->capabilities()->get()));
        });
    }

    public function detachCapability(Request $request, Product $product, Capability $capability): JsonResponse
    {
        return DB::transaction(function () use ($request, $product, $capability) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $product->capabilities()->detach($capability->id);

            $this->audit->record('product.capability_revoked', $request, resourceType: 'Product', resourceId: $product->id, oldValue: ['capability_id' => $capability->id]);

            return $this->ok(null);
        });
    }

    private function transitionTo(Request $request, Product $product, string $to, array $allowedFrom): JsonResponse
    {
        if ($response = $this->guardTransition($product, $to, $allowedFrom)) {
            return $response;
        }

        $old = $product->status;
        $product->update(['status' => $to]);

        $this->audit->record('product.status_changed', $request, resourceType: 'Product', resourceId: $product->id, oldValue: ['status' => $old], newValue: ['status' => $to]);

        return $this->ok(new ProductResource($product));
    }
}
