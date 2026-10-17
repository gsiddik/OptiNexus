<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: ecosystem
 *
 * The ecosystem portal and its admin API belong to the platform operators only (audit R07/A24). A human token whose holder has no
 * global ecosystem access is refused here, on the server, whatever the front end shows: tenant users and tenant administrators
 * manage their own users from the product they use, through the delegation API, never through these routes. It runs after
 * authentication and before the per-route permission check; a request without a human user is left to the authentication
 * middleware (or to the machine-to-machine middleware of its route).
 */
class EnsureEcosystemAccess
{
    public const ERROR_CODE = 'ECOSYSTEM_ACCESS_REQUIRED';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditService $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($this->authorization->hasEcosystemAccess($user)) {
            return $next($request);
        }

        $this->audit->record(
            'authorization.ecosystem_denied',
            $request,
            actor: $user,
            metadata: ['route' => $request->path(), 'method' => $request->method()],
        );

        return response()->json([
            'success' => false,
            'error' => [
                'code' => self::ERROR_CODE,
                'message' => 'This account cannot use the ecosystem portal. Manage your users from your application.',
                'details' => null,
            ],
        ], 403);
    }
}
