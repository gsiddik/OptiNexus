<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\AuthorizationCheckRequest;
use App\Models\Application;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;

/**
 * The critical cross-application integration endpoint: lets any registered
 * application (OptiFleet, OptiAccounting, VMS, Taxi Management, ...) ask
 * CGO whether an identity may perform an action, without needing to
 * replicate CGO's RBAC logic locally.
 */
class AuthorizationController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditService $audit,
    ) {}

    public function check(AuthorizationCheckRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::query()->find($data['user_id']);
        if (! $user) {
            return $this->ok(['allowed' => false, 'permission' => $data['permission'], 'scope' => null]);
        }

        $tenant = ! empty($data['tenant_id']) ? Tenant::query()->find($data['tenant_id']) : null;

        $application = Application::query()->where('application_code', $data['application_code'])->first();
        if (! $application) {
            return $this->fail('RESOURCE_NOT_FOUND', 'Unknown application_code.', 404);
        }

        $result = $this->authorization->check($user, $tenant, $application, $data['permission']);

        if (! $result['allowed']) {
            $this->audit->record(
                'authorization.denied',
                $request,
                actor: $user,
                tenantId: $tenant?->id,
                applicationId: $application->id,
                metadata: [
                    'permission' => $data['permission'],
                    'reason' => $result['reason'],
                    'resource_context' => $data['resource_context'] ?? null,
                ],
            );
        }

        return $this->ok([
            'allowed' => $result['allowed'],
            'permission' => $data['permission'],
            'scope' => $result['scope'],
        ]);
    }
}
