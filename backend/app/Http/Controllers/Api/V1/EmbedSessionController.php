<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\EmbedSessionRequest;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Services\AuditService;
use App\Services\Embed\EmbedSessionService;
use Illuminate\Http\JsonResponse;

class EmbedSessionController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly EmbedSessionService $embeds) {}

    /** Machine-to-machine (scope `embed.create`, tenant bound): a short-lived, read-only embed URL of another application. */
    public function store(EmbedSessionRequest $request): JsonResponse
    {
        /** @var ServiceAccount $account */
        $account = $request->attributes->get('service_account');
        $caller = $account->application;
        if (! $caller) {
            return $this->fail('SERVICE_ACCOUNT_NOT_BOUND', 'This service account is not bound to an application.', 403);
        }

        $tenant = Tenant::query()->find($request->input('tenant_id'));
        if (! $tenant) {
            return $this->fail('TENANT_NOT_FOUND', 'Unknown tenant.', 404);
        }

        [$session, $code, $message] = $this->embeds->open($caller, $tenant, $request->input('target'), (int) $request->input('ttl_seconds', 900));
        if ($session === null) {
            return $this->fail($code, $message, $code === 'EMBED_UNAVAILABLE' ? 502 : 403);
        }

        app(AuditService::class)->record('embed.session_opened', $request, tenantId: $tenant->id, applicationId: $caller->id,
            resourceType: 'EmbedSession', resourceId: null, metadata: ['target' => $request->input('target'), 'ttl_seconds' => $session['ttl_seconds']]);

        return $this->created($session);
    }
}
