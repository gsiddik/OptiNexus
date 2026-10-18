<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\BootstrapTenantAdminRequest;
use App\Http\Requests\V1\VerifyAdminEmailRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ecosystem\AdminBootstrapException;
use App\Services\Ecosystem\TenantAdminBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * First Admin Tenant of a tenant, created by an ecosystem operator (owner decision 2026-10-18, second batch; contract:
 * GOVERNANCE_CONTRACTS.md 2c). Ecosystem operators only - the `ecosystem` gate sits on the route group. Responses that carry a
 * temporary password are never cached and are the only place it ever appears.
 */
class TenantAdminAccountController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly TenantAdminBootstrapService $bootstrap) {}

    public function index(Tenant $tenant): JsonResponse
    {
        return $this->ok($this->bootstrap->list($tenant));
    }

    public function store(BootstrapTenantAdminRequest $request, Tenant $tenant): JsonResponse
    {
        return $this->guarded(function () use ($request, $tenant): JsonResponse {
            $result = $this->bootstrap->provision($tenant, $request->validated(), $request->user(), $request);

            $payload = $this->bootstrap->view($tenant, $result['user']) + ['outcome' => $result['outcome']];

            return $this->withCredential($payload, $result['user'], $result['temporary_password'], 201);
        });
    }

    public function verifyEmail(VerifyAdminEmailRequest $request, Tenant $tenant, User $user): JsonResponse
    {
        return $this->guarded(function () use ($request, $tenant, $user): JsonResponse {
            $verified = $this->bootstrap->verifyEmail($tenant, $user, $request->validated('email'), $request->validated('reason'), $request->user(), $request);

            return $this->ok($this->bootstrap->view($tenant, $verified));
        });
    }

    public function reissueCredential(Request $request, Tenant $tenant, User $user): JsonResponse
    {
        return $this->guarded(function () use ($request, $tenant, $user): JsonResponse {
            $result = $this->bootstrap->reissue($tenant, $user, $request->user(), $request);

            return $this->withCredential($this->bootstrap->view($tenant, $result['user']), $result['user'], $result['temporary_password'], 200);
        });
    }

    /** @param  array<string, mixed>  $payload */
    private function withCredential(array $payload, User $user, ?string $password, int $status): JsonResponse
    {
        $payload['temporary_credential'] = $password === null ? null : [
            'password' => $password,
            'expires_at' => $user->temporary_password_expires_at?->toIso8601String(),
            'shown_once' => true,
        ];

        return $this->ok($payload, status: $status)->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    private function guarded(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (AdminBootstrapException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->status, $e->details);
        }
    }
}
