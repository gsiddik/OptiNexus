<?php

namespace App\Http\Middleware;

use App\Models\GatewayRequestLog;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `service_account:*`. Decides which tenant a gateway call acts
 * for and refuses anything the caller's application is not subscribed to:
 *
 *  - a tenant-bound service account always acts for its own tenant (an
 *    X-Tenant-Id header naming a different tenant is rejected);
 *  - a platform-level service account must name the tenant in X-Tenant-Id;
 *  - in both cases the tenant must be ACTIVE and have the caller's
 *    application assigned and ACTIVE.
 *
 * Every call, including refusals, is written to gateway_request_logs.
 */
class ResolveGatewayTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        /** @var ServiceAccount $account */
        $account = $request->attributes->get('service_account');
        $requested = $request->header('X-Tenant-Id');

        $tenantId = $account->tenant_id ?? $requested;
        $denial = null;

        if (! $tenantId) {
            $denial = ['TENANT_REQUIRED', 'Send the X-Tenant-Id header: this service account is not bound to a tenant.', 422];
        } elseif ($account->tenant_id && $requested && $requested !== $account->tenant_id) {
            $denial = ['TENANT_MISMATCH', 'This service account is bound to a different tenant.', 403];
        } elseif (! $account->application_id) {
            $denial = ['APPLICATION_REQUIRED', 'This service account is not bound to an application.', 403];
        } else {
            $tenant = Tenant::query()->find($tenantId);
            $assigned = $tenant?->isActive() && $tenant->applications()
                ->where('applications.id', $account->application_id)
                ->wherePivot('status', 'ACTIVE')
                ->exists();

            if (! $assigned) {
                $denial = ['TENANT_NOT_SUBSCRIBED', 'The tenant is unknown, inactive, or not subscribed to this application.', 403];
            }
        }

        $response = $denial
            ? response()->json(['success' => false, 'error' => ['code' => $denial[0], 'message' => $denial[1], 'details' => null]], $denial[2])
            : $this->proceed($request, $next, $tenantId);

        GatewayRequestLog::create([
            'tenant_id' => $denial ? null : $tenantId,
            'application_id' => $account->application_id,
            'service_account_id' => $account->id,
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'correlation_id' => $request->header('X-Correlation-Id'),
        ]);

        return $response;
    }

    private function proceed(Request $request, Closure $next, string $tenantId): Response
    {
        $request->attributes->set('gateway_tenant_id', $tenantId);

        return $next($request);
    }
}
