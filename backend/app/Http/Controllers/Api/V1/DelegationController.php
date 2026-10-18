<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\UserInvitation;
use App\Services\Delegation\DelegationContext;
use App\Services\Delegation\DelegationException;
use App\Services\Delegation\DelegationService;
use App\Services\Delegation\InvitationService;
use App\Services\Seats\SeatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Tenant delegation API (audit R08/A25), machine-to-machine only. A product backend calls it with its own service account
 * (scope tenant.delegate) when an Admin Tenant manages users and custom roles from the product UI. The tenant comes from the
 * path, the acting Admin Tenant from `acting_user_id`; both are re-verified by DelegationService on every call. Nothing here
 * touches the ecosystem catalog, plans, entitlements or ecosystem roles.
 */
class DelegationController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly DelegationService $delegation,
        private readonly InvitationService $invitations,
        private readonly SeatService $seats,
    ) {}

    /** Used, reserved, limit and what is left for this tenant and product, as OptiNexus counts them (the only count that decides). */
    public function seats(Request $request, string $tenant): JsonResponse
    {
        return $this->guarded($request, $tenant, fn (DelegationContext $context) => $this->ok($this->seats->status($context->tenant, $context->application)->toArray()));
    }

    public function catalog(Request $request, string $tenant): JsonResponse
    {
        return $this->guarded($request, $tenant, fn (DelegationContext $context) => $this->ok($this->delegation->catalog($context)));
    }

    public function users(Request $request, string $tenant): JsonResponse
    {
        return $this->guarded($request, $tenant, function (DelegationContext $context) use ($request) {
            $result = $this->delegation->listUsers($context, $request->query('search'), (int) $request->query('per_page', 25));

            return $this->ok($result['items'], ['pagination' => $result['pagination'], 'seats' => $this->seats->status($context->tenant, $context->application)->toArray()]);
        });
    }

    /**
     * Deprecated (owner decision 2026-10-18): new people are added by invitation (see `invitations`), which verifies the e-mail and lets the
     * person choose their own password. Kept working for compatibility; `NEXUS_DELEGATION_DIRECT_USERS=false` switches it off per deployment.
     */
    public function addUser(Request $request, string $tenant): JsonResponse
    {
        if (! config('invitations.allow_direct_user_creation')) {
            return $this->fail('DIRECT_USER_CREATION_DISABLED', 'Add people with an invitation instead.', 410);
        }

        return $this->deprecated($request, $tenant, $this->guarded($request, $tenant, function (DelegationContext $context) use ($request) {
            $data = $request->validate([
                'email' => ['required', 'email:rfc', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'initial_password' => ['nullable', 'string', 'min:12', 'max:128'],
                'role_ids' => ['sometimes', 'array', 'max:20'],
                'role_ids.*' => ['uuid'],
            ]);

            $result = $this->delegation->addUser($context, $data, $request);

            $view = ['outcome' => $result['outcome']] + $this->delegation->userView($context, $result['user']);

            return $result['outcome'] === 'created' ? $this->created($view) : $this->ok($view);
        }));
    }

    public function invitations(Request $request, string $tenant): JsonResponse
    {
        return $this->guarded($request, $tenant, function (DelegationContext $context) use ($request) {
            $data = $request->validate([
                'status' => ['sometimes', 'nullable', Rule::in(UserInvitation::STATUSES)],
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);

            $result = $this->invitations->list($context, $data['status'] ?? null, $data['search'] ?? null, (int) ($data['per_page'] ?? 25));

            return $this->ok($result['items'], ['pagination' => $result['pagination'], 'seats' => $this->seats->status($context->tenant, $context->application)->toArray()]);
        });
    }

    public function createInvitation(Request $request, string $tenant): JsonResponse
    {
        return $this->guarded($request, $tenant, function (DelegationContext $context) use ($request) {
            $data = $request->validate([
                'email' => ['required', 'email:rfc', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'role_ids' => ['sometimes', 'array', 'max:20'],
                'role_ids.*' => ['uuid'],
                'locale' => ['sometimes', 'nullable', Rule::in(config('invitations.locales'))],
            ]);

            return $this->created($this->invitations->view($this->invitations->create($context, $data, $request)));
        });
    }

    public function resendInvitation(Request $request, string $tenant, string $invitation): JsonResponse
    {
        return $this->guarded($request, $tenant, fn (DelegationContext $context) => $this->ok($this->invitations->view($this->invitations->resend($context, $invitation, $request))));
    }

    public function revokeInvitation(Request $request, string $tenant, string $invitation): JsonResponse
    {
        return $this->guarded($request, $tenant, function (DelegationContext $context) use ($request, $invitation) {
            $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255']]);

            return $this->ok($this->invitations->view($this->invitations->revoke($context, $invitation, $data['reason'] ?? null, $request)));
        });
    }

    public function setUserRoles(Request $request, string $tenant, string $user): JsonResponse
    {
        return $this->guarded($request, $tenant, function (DelegationContext $context) use ($request, $user) {
            $data = $request->validate(['role_ids' => ['present', 'array', 'max:20'], 'role_ids.*' => ['uuid']]);

            $target = $this->delegation->findTarget($context, $user);
            $this->delegation->setUserRoles($context, $target, $data['role_ids'], $request);

            return $this->ok($this->delegation->userView($context, $target->refresh()));
        });
    }

    public function revokeUser(Request $request, string $tenant, string $user): JsonResponse
    {
        return $this->guarded($request, $tenant, function (DelegationContext $context) use ($request, $user) {
            $this->delegation->revokeUser($context, $this->delegation->findTarget($context, $user), $request);

            return $this->ok(null);
        });
    }

    public function createRole(Request $request, string $tenant): JsonResponse
    {
        return $this->guarded($request, $tenant, function (DelegationContext $context) use ($request) {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'description' => ['nullable', 'string', 'max:500'],
                'permissions' => ['required', 'array', 'min:1', 'max:200'],
                'permissions.*' => ['string', 'max:150'],
            ]);

            return $this->created($this->delegation->roleView($this->delegation->createRole($context, $data, $request)));
        });
    }

    public function updateRole(Request $request, string $tenant, string $role): JsonResponse
    {
        return $this->guarded($request, $tenant, function (DelegationContext $context) use ($request, $role) {
            $data = $request->validate([
                'name' => ['sometimes', 'string', 'max:120'],
                'description' => ['sometimes', 'nullable', 'string', 'max:500'],
                'permissions' => ['sometimes', 'array', 'min:1', 'max:200'],
                'permissions.*' => ['string', 'max:150'],
            ]);

            return $this->ok($this->delegation->roleView($this->delegation->updateRole($context, $this->delegation->findRole($context, $role), $data, $request)));
        });
    }

    public function deleteRole(Request $request, string $tenant, string $role): JsonResponse
    {
        return $this->guarded($request, $tenant, function (DelegationContext $context) use ($request, $role) {
            $this->delegation->deleteRole($context, $this->delegation->findRole($context, $role), $request);

            return $this->ok(null);
        });
    }

    /** Verifies the call (product, tenant, acting Admin Tenant) and turns a refusal into the API error envelope. */
    private function guarded(Request $request, string $tenantId, callable $action): JsonResponse
    {
        $request->validate(['acting_user_id' => ['required', 'uuid']]);

        // Resolved here, after the service account authenticated, so an anonymous caller cannot probe which tenants exist.
        $tenant = Str::isUuid($tenantId) ? Tenant::query()->find($tenantId) : null;

        if (! $tenant) {
            return $this->fail('RESOURCE_NOT_FOUND', 'The requested resource could not be found.', 404);
        }

        /** @var ServiceAccount $serviceAccount */
        $serviceAccount = $request->attributes->get('service_account');

        try {
            $context = $this->delegation->context($serviceAccount, $tenant, (string) $request->input('acting_user_id'));

            return $action($context);
        } catch (DelegationException $e) {
            $response = $this->fail($e->errorCode, $e->getMessage(), $e->status, $e->details);

            if ($e->status === 429 && is_array($e->details) && isset($e->details['retry_after'])) {
                $response->header('Retry-After', (string) max(1, (int) $e->details['retry_after']));
            }

            return $response;
        }
    }

    /** Marks the deprecated direct-creation path: the successor is the invitation endpoint of the same tenant. */
    private function deprecated(Request $request, string $tenantId, JsonResponse $response): JsonResponse
    {
        $response->header('Deprecation', 'true');
        $response->header('Link', '<'.url("/api/v1/delegation/tenants/{$tenantId}/invitations").'>; rel="successor-version"');

        return $response;
    }
}
