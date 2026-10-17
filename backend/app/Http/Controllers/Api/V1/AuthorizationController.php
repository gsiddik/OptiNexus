<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\AuthorizationCheckRequest;
use App\Http\Requests\V1\EffectivePermissionsRequest;
use App\Models\Application;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use App\Services\Oidc\SsoAccessResolver;
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
        private readonly SsoAccessResolver $sso,
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

    /**
     * Every permission one user holds in one tenant for the caller's own application, in one request (the bulk counterpart of
     * check(), schema 1.0). The application is the one the authenticated service account is bound to; a service account that
     * is bound to nothing, or to another application or tenant, gets 403 and learns nothing. Only granted keys come back, each
     * with its own scope, and a deny carries the same reason code check() would log. `ttl_seconds` is the longest a caller may
     * reuse the answer: a revoked grant is therefore in force at most that long after the change.
     */
    public function effectivePermissions(EffectivePermissionsRequest $request): JsonResponse
    {
        $data = $request->validated();

        /** @var ServiceAccount $serviceAccount */
        $serviceAccount = $request->attributes->get('service_account');
        $application = $serviceAccount->application;

        if (! $application) {
            return $this->fail('SERVICE_ACCOUNT_NOT_BOUND', 'This service account is not bound to an application.', 403);
        }

        if (! empty($data['application_code']) && $data['application_code'] !== $application->application_code) {
            return $this->fail('APPLICATION_MISMATCH', 'The service account is bound to another application.', 403);
        }

        if ($serviceAccount->tenant_id !== null && $serviceAccount->tenant_id !== $data['tenant_id']) {
            return $this->fail('TENANT_MISMATCH', 'The service account is bound to another tenant.', 403);
        }

        $user = User::query()->find($data['user_id']);
        $tenant = Tenant::query()->find($data['tenant_id']);

        $access = match (true) {
            ! $user => ['allowed' => false, 'reason' => 'USER_NOT_FOUND', 'permissions' => []],
            ! $tenant => ['allowed' => false, 'reason' => 'TENANT_NOT_FOUND', 'permissions' => []],
            default => $this->authorization->effectiveAccess($user, $tenant, $application),
        };

        if (! $access['allowed'] && $user) {
            $this->audit->record(
                'authorization.denied',
                $request,
                actor: $user,
                tenantId: $tenant?->id,
                applicationId: $application->id,
                metadata: ['permission' => '*', 'reason' => $access['reason'], 'bulk' => true],
            );
        }

        return $this->ok([
            'schema_version' => '1.0',
            'user_id' => $data['user_id'],
            'tenant_id' => $data['tenant_id'],
            'application_code' => $application->application_code,
            'allowed' => $access['allowed'],
            'deny_reason' => $access['reason'],
            'permissions' => collect($access['permissions'])->map(fn (string $scope, string $key): array => ['permission' => $key, 'scope' => $scope])->values()->all(),
            'evaluated_at' => now()->toIso8601String(),
            'ttl_seconds' => (int) config('m2m.permission_ttl_seconds'),
        ]);
    }

    /**
     * The products a person may open from the application the caller is bound to, in the tenant they are using (audit A29,
     * the data behind every product switcher). Nothing but names, launch URLs and the tenant is returned; the governance portal
     * is never listed and only applications the tenant is subscribed to and the user was assigned to appear. `allowed` and
     * `deny_reason` revalidate the caller's own session: when false, the product must end its local session. Same binding rules
     * as effective-permissions: the application is the service account's, never the body's.
     */
    public function productContext(EffectivePermissionsRequest $request): JsonResponse
    {
        $data = $request->validated();

        /** @var ServiceAccount $serviceAccount */
        $serviceAccount = $request->attributes->get('service_account');
        $application = $serviceAccount->application;

        if (! $application) {
            return $this->fail('SERVICE_ACCOUNT_NOT_BOUND', 'This service account is not bound to an application.', 403);
        }

        if ($serviceAccount->tenant_id !== null && $serviceAccount->tenant_id !== $data['tenant_id']) {
            return $this->fail('TENANT_MISMATCH', 'The service account is bound to another tenant.', 403);
        }

        $user = User::query()->find($data['user_id']);
        $tenant = Tenant::query()->find($data['tenant_id']);

        $denyReason = match (true) {
            ! $user => 'user_not_found',
            ! $tenant => 'tenant_not_found',
            default => $this->sso->denialReason($user, $application, $tenant),
        };

        $products = $denyReason === null
            ? collect($this->sso->launchableApplications($user, $tenant))->reject(fn (array $app): bool => $app['code'] === 'cgo')
                ->map(fn (array $app): array => $app + ['current' => $app['code'] === $application->application_code])->values()->all()
            : [];

        return $this->ok([
            'schema_version' => '1.0',
            'user_id' => $data['user_id'],
            'tenant' => $tenant && $denyReason === null ? ['id' => $tenant->id, 'code' => $tenant->tenant_code, 'name' => $tenant->name] : null,
            'application_code' => $application->application_code,
            'allowed' => $denyReason === null,
            'deny_reason' => $denyReason,
            'products' => $products,
            'evaluated_at' => now()->toIso8601String(),
            'ttl_seconds' => (int) config('m2m.permission_ttl_seconds'),
        ]);
    }
}
