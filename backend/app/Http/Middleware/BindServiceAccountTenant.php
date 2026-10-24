<?php

namespace App\Http\Middleware;

use App\Models\ServiceAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant binding for machine-to-machine routes that take `tenant_id` from the body (entitlements/check, authorization/check,
 * access/evaluate, events, usage-events). Runs after `service_account`. A service account bound to a tenant may only speak for
 * that tenant: another tenant_id gets 403 TENANT_MISMATCH (same code as effective-permissions), and a missing one is filled in
 * with its own tenant so it can never ask a tenantless question. A platform service account (no tenant) is unchanged.
 *
 * Usage: middleware(['service_account:entitlement.check', 'service_account.tenant'])
 */
class BindServiceAccountTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ServiceAccount|null $serviceAccount */
        $serviceAccount = $request->attributes->get('service_account');
        $bound = $serviceAccount?->tenant_id;

        if ($bound === null) {
            return $next($request);
        }

        $requested = $request->input('tenant_id');

        if ($requested === null || $requested === '') {
            $request->merge(['tenant_id' => $bound]);
        } elseif ($requested !== $bound) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'TENANT_MISMATCH', 'message' => 'The service account is bound to another tenant.', 'details' => null],
            ], 403);
        }

        return $next($request);
    }
}
