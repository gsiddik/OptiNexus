<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Api\V1\Concerns\HandlesLifecycleTransitions;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreApplicationRequest;
use App\Http\Requests\V1\UpdateApplicationRequest;
use App\Http\Resources\V1\ApplicationResource;
use App\Http\Resources\V1\CapabilityResource;
use App\Http\Resources\V1\PermissionResource;
use App\Models\Application;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    use ApiResponses, HandlesLifecycleTransitions;

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Application::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")->orWhere('application_code', 'ilike', "%{$search}%");
            });
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 15));

        return $this->paginated($paginator, ApplicationResource::class);
    }

    public function store(StoreApplicationRequest $request): JsonResponse
    {
        $application = Application::create([...$request->validated(), 'status' => Application::STATUS_DRAFT]);

        $this->audit->record('application.created', $request, resourceType: 'Application', resourceId: $application->id, newValue: $application->toArray(), applicationId: $application->id);

        return $this->created(new ApplicationResource($application));
    }

    public function show(Application $application): JsonResponse
    {
        return $this->ok(new ApplicationResource($application));
    }

    public function update(UpdateApplicationRequest $request, Application $application): JsonResponse
    {
        $old = $application->toArray();
        $application->update($request->validated());

        $this->audit->record('application.updated', $request, resourceType: 'Application', resourceId: $application->id, oldValue: $old, newValue: $application->toArray(), applicationId: $application->id);

        return $this->ok(new ApplicationResource($application));
    }

    public function submit(Request $request, Application $application): JsonResponse
    {
        return $this->transitionTo($request, $application, Application::STATUS_REVIEW, [Application::STATUS_DRAFT]);
    }

    public function approve(Request $request, Application $application): JsonResponse
    {
        return $this->transitionTo($request, $application, Application::STATUS_APPROVED, [Application::STATUS_REVIEW]);
    }

    public function publish(Request $request, Application $application): JsonResponse
    {
        return $this->transitionTo($request, $application, Application::STATUS_PUBLISHED, [Application::STATUS_APPROVED]);
    }

    public function deprecate(Request $request, Application $application): JsonResponse
    {
        return $this->transitionTo($request, $application, Application::STATUS_DEPRECATED, [Application::STATUS_PUBLISHED]);
    }

    public function retire(Request $request, Application $application): JsonResponse
    {
        return $this->transitionTo($request, $application, Application::STATUS_RETIRED, [Application::STATUS_DEPRECATED, Application::STATUS_PUBLISHED]);
    }

    public function capabilities(Application $application): JsonResponse
    {
        $capabilities = $application->capabilities()->whereNull('parent_id')->with('children.children.children.children.children')->orderBy('sort_order')->get();

        return $this->ok(CapabilityResource::collection($capabilities));
    }

    public function permissions(Request $request, Application $application): JsonResponse
    {
        $query = $application->permissions();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $this->ok(PermissionResource::collection($query->get()));
    }

    private function transitionTo(Request $request, Application $application, string $to, array $allowedFrom): JsonResponse
    {
        if ($response = $this->guardTransition($application, $to, $allowedFrom)) {
            return $response;
        }

        $old = $application->status;
        $application->update(['status' => $to]);

        $this->audit->record(
            'application.status_changed',
            $request,
            resourceType: 'Application',
            resourceId: $application->id,
            oldValue: ['status' => $old],
            newValue: ['status' => $to],
            applicationId: $application->id,
        );

        return $this->ok(new ApplicationResource($application));
    }
}
