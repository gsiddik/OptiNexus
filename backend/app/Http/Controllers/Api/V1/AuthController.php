<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\LoginRequest;
use App\Http\Resources\V1\RoleResource;
use App\Http\Resources\V1\TenantResource;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly AuditService $audit,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * Central login. Issues a Sanctum personal access token for human users.
     * This is the "central authentication foundation" integrated apps and
     * the CGO admin SPA both build on; SSO/OIDC federation (Entra ID,
     * Google Workspace, SAML) plugs in ahead of this same token issuance
     * step in a future phase without changing the contract below.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $throttleKey = 'login:'.strtolower($request->input('email')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return $this->fail('RATE_LIMITED', "Too many login attempts. Try again in {$seconds} seconds.", 429);
        }

        $user = User::query()->where('email', $request->input('email'))->first();

        if (! $user || ! $user->password || ! Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            $this->audit->record('auth.login_failed', $request, actorIdentity: $request->input('email'), metadata: ['reason' => 'invalid_credentials']);

            return $this->fail('UNAUTHENTICATED', 'The provided credentials are incorrect.', 401);
        }

        if (! $user->isActive()) {
            RateLimiter::hit($throttleKey, 60);

            $this->audit->record('auth.login_failed', $request, actor: $user, metadata: ['reason' => 'account_not_active', 'status' => $user->status]);

            return $this->fail('UNAUTHORIZED', 'This account is not active.', 403);
        }

        RateLimiter::clear($throttleKey);

        $token = $user->createToken($request->input('device_name', 'cgo-api'));
        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record('auth.login', $request, actor: $user);

        return $this->ok([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        $this->audit->record('auth.logout', $request, actor: $request->user());

        return $this->ok(['message' => 'Logged out.']);
    }

    /**
     * Returns the authenticated user's effective governance context: active
     * tenant, all tenants they belong to, roles, and effective permissions.
     * Integrated applications call this after authenticating a user to
     * learn what that user is allowed to do.
     */
    public function context(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $request->query('tenant_id');
        $applicationId = $request->query('application_id');

        if ($tenantId && ! $user->tenantMemberships()->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->exists()) {
            return $this->fail('TENANT_ACCESS_DENIED', 'You are not an active member of this tenant.', 403);
        }

        $permissions = $this->authorization->effectivePermissions($user, $tenantId, $applicationId);

        $roleIds = $user->userRoles()
            ->when($tenantId, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('tenant_id')->orWhere('tenant_id', $tenantId)))
            ->when(! $tenantId, fn ($q) => $q->whereNull('tenant_id'))
            ->pluck('role_id');

        return $this->ok([
            'user' => new UserResource($user),
            'active_tenant_id' => $tenantId,
            'available_tenants' => TenantResource::collection($user->tenants),
            'roles' => RoleResource::collection(\App\Models\Role::query()->whereIn('id', $roleIds)->get()),
            'effective_permissions' => $permissions->map(fn ($v, $key) => ['permission_key' => $key, 'scope' => $v['scope']])->values(),
        ]);
    }

    /**
     * Lightweight introspection for a Sanctum bearer token, mirroring the
     * shape of RFC 7662 (OAuth 2.0 Token Introspection) closely enough for
     * integrated applications to validate a CGO-issued human session token.
     */
    public function introspect(Request $request): JsonResponse
    {
        $token = (string) $request->input('token');
        $model = \Laravel\Sanctum\PersonalAccessToken::findToken($token);

        if (! $model || ($model->expires_at && $model->expires_at->isPast())) {
            return $this->ok(['active' => false]);
        }

        $user = $model->tokenable;
        if (! $user instanceof User || ! $user->isActive()) {
            return $this->ok(['active' => false]);
        }

        return $this->ok([
            'active' => true,
            'sub' => $user->id,
            'email' => $user->email,
            'token_type' => 'Bearer',
            'exp' => $model->expires_at?->timestamp,
            'iat' => $model->created_at?->timestamp,
        ]);
    }
}
