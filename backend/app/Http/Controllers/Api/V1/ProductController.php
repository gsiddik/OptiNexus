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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create([...$request->validated(), 'status' => Product::STATUS_DRAFT]);

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
        if ($product->applications()->where('applications.id', $application->id)->exists()) {
            return $this->fail('DUPLICATE_RESOURCE', 'This application is already assigned to the product.', 409);
        }

        $product->applications()->attach($application->id, ['id' => (string) Str::uuid()]);

        $this->audit->record('product.application_assigned', $request, resourceType: 'Product', resourceId: $product->id, newValue: ['application_id' => $application->id], applicationId: $application->id);

        return $this->created(ApplicationResource::collection($product->applications()->get()));
    }

    public function detachApplication(Request $request, Product $product, Application $application): JsonResponse
    {
        $product->applications()->detach($application->id);

        $this->audit->record('product.application_revoked', $request, resourceType: 'Product', resourceId: $product->id, oldValue: ['application_id' => $application->id], applicationId: $application->id);

        return $this->ok(null);
    }

    public function capabilities(Product $product): JsonResponse
    {
        return $this->ok(CapabilityResource::collection($product->capabilities));
    }

    public function attachCapability(Request $request, Product $product, Capability $capability): JsonResponse
    {
        if ($product->capabilities()->where('capabilities.id', $capability->id)->exists()) {
            return $this->fail('DUPLICATE_RESOURCE', 'This capability is already assigned to the product.', 409);
        }

        $product->capabilities()->attach($capability->id, ['id' => (string) Str::uuid()]);

        $this->audit->record('product.capability_assigned', $request, resourceType: 'Product', resourceId: $product->id, newValue: ['capability_id' => $capability->id]);

        return $this->created(CapabilityResource::collection($product->capabilities()->get()));
    }

    public function detachCapability(Request $request, Product $product, Capability $capability): JsonResponse
    {
        $product->capabilities()->detach($capability->id);

        $this->audit->record('product.capability_revoked', $request, resourceType: 'Product', resourceId: $product->id, oldValue: ['capability_id' => $capability->id]);

        return $this->ok(null);
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
