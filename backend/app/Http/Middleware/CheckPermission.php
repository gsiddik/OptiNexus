<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: permission:{permission_key}[,{tenantRouteParam}]
 *
 * Enforces server-side RBAC for the CGO admin/API surface. When a tenant
 * route parameter name is given, the permission is resolved (and therefore
 * tenant-isolated) against that tenant; otherwise it is resolved globally,
 * which only SYSTEM-scoped role assignments can satisfy.
 *
 * The tenant parameter can name either a route-bound model that carries a
 * tenant_id (e.g. `tenant` itself, or `role`/`serviceAccount`) - in which
 * case a null tenant_id on that model legitimately means "global scope",
 * not "not found" - or, when no such route parameter exists, a request
 * body/query field (e.g. `tenant_id` on /users/{user}/roles/{role}).
 */
class CheckPermission
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditService $audit,
    ) {}

    public function handle(Request $request, Closure $next, string $permissionKey, ?string $tenantParam = null): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication is required to access this resource.',
                    'details' => null,
                ],
            ], 401);
        }

        $tenantId = $this->resolveTenantId($request, $tenantParam);

        if (! $this->authorization->userHasPermission($user, $permissionKey, $tenantId)) {
            $code = $tenantParam ? 'TENANT_ACCESS_DENIED' : 'UNAUTHORIZED';

            $this->audit->record(
                'authorization.denied',
                $request,
                actor: $user,
                tenantId: $tenantId,
                metadata: ['permission' => $permissionKey, 'route' => $request->path(), 'method' => $request->method()],
            );

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => $code,
                    'message' => "You do not have the [{$permissionKey}] permission required for this action.",
                    'details' => null,
                ],
            ], 403);
        }

        return $next($request);
    }

    private function resolveTenantId(Request $request, ?string $tenantParam): ?string
    {
        if (! $tenantParam) {
            return null;
        }

        $route = $request->route();

        if ($route?->hasParameter($tenantParam)) {
            $param = $route->parameter($tenantParam);

            if ($param instanceof Tenant) {
                return $param->id;
            }

            if ($param instanceof Model) {
                // A bound model with no tenant_id (e.g. a SYSTEM role) is
                // legitimately global-scoped - not an unresolved parameter.
                return $param->getAttribute('tenant_id');
            }

            if (is_string($param)) {
                return $param;
            }
        }

        // No matching route-model parameter: fall back to a request field
        // of the same name (e.g. tenant_id in the JSON body or query string)
        // so tenant-scoped writes on tenant-agnostic routes (like
        // /users/{user}/roles/{role}) can still be isolated correctly.
        $fallback = $request->input($tenantParam) ?? $request->query($tenantParam);

        return is_string($fallback) ? $fallback : null;
    }
}
