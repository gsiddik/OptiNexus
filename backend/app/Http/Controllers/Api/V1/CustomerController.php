<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreCustomerRequest;
use App\Http\Requests\V1\UpdateCustomerRequest;
use App\Http\Resources\V1\CustomerResource;
use App\Models\Customer;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Customer::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('legal_name', 'ilike', "%{$search}%")
                    ->orWhere('customer_code', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $sort = $request->query('sort', '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (in_array($column, ['created_at', 'legal_name', 'customer_code', 'status'], true)) {
            $query->orderBy($column, $direction);
        }

        $paginator = $query->paginate((int) $request->query('per_page', 15));

        return $this->paginated($paginator, CustomerResource::class);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create([...$request->validated(), 'status' => Customer::STATUS_ACTIVE]);

        $this->audit->record('customer.created', $request, resourceType: 'Customer', resourceId: $customer->id, newValue: $customer->toArray(), customerId: $customer->id);

        return $this->created(new CustomerResource($customer));
    }

    public function show(Customer $customer): JsonResponse
    {
        return $this->ok(new CustomerResource($customer));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $old = $customer->toArray();
        $customer->update($request->validated());

        $this->audit->record('customer.updated', $request, resourceType: 'Customer', resourceId: $customer->id, oldValue: $old, newValue: $customer->toArray(), customerId: $customer->id);

        return $this->ok(new CustomerResource($customer));
    }

    public function updateStatus(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', Customer::STATUSES)],
        ]);

        return $this->transitionTo($request, $customer, $validated['status'], match ($validated['status']) {
            Customer::STATUS_ACTIVE => [Customer::STATUS_SUSPENDED],
            Customer::STATUS_SUSPENDED => [Customer::STATUS_ACTIVE],
            Customer::STATUS_TERMINATED => [Customer::STATUS_ACTIVE, Customer::STATUS_SUSPENDED],
            Customer::STATUS_ARCHIVED => [Customer::STATUS_ACTIVE, Customer::STATUS_SUSPENDED, Customer::STATUS_TERMINATED],
            default => [],
        });
    }

    public function activate(Request $request, Customer $customer): JsonResponse
    {
        return $this->transitionTo($request, $customer, Customer::STATUS_ACTIVE, [Customer::STATUS_SUSPENDED]);
    }

    public function suspend(Request $request, Customer $customer): JsonResponse
    {
        return $this->transitionTo($request, $customer, Customer::STATUS_SUSPENDED, [Customer::STATUS_ACTIVE]);
    }

    public function terminate(Request $request, Customer $customer): JsonResponse
    {
        return $this->transitionTo($request, $customer, Customer::STATUS_TERMINATED, [Customer::STATUS_ACTIVE, Customer::STATUS_SUSPENDED]);
    }

    public function archive(Request $request, Customer $customer): JsonResponse
    {
        return $this->transitionTo($request, $customer, Customer::STATUS_ARCHIVED, [Customer::STATUS_ACTIVE, Customer::STATUS_SUSPENDED, Customer::STATUS_TERMINATED]);
    }

    private function transitionTo(Request $request, Customer $customer, string $to, array $allowedFrom): JsonResponse
    {
        if ($response = $this->guardTransition($customer, $to, $allowedFrom)) {
            return $response;
        }

        $old = $customer->status;
        $customer->update(['status' => $to]);

        $this->audit->record(
            'customer.status_changed',
            $request,
            resourceType: 'Customer',
            resourceId: $customer->id,
            oldValue: ['status' => $old],
            newValue: ['status' => $to],
            customerId: $customer->id,
        );

        return $this->ok(new CustomerResource($customer));
    }
}
