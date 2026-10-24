<?php

namespace App\Services\Embed;

use App\Models\Application;
use App\Models\Tenant;
use App\Services\Commercial\SubscriptionAccess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Embed sessions (owner decision K3). An application (OptiDeli) that wants to show another one (OptiRadar) in an iframe asks
 * Nexus; Nexus checks that the tenant is entitled to both, asks the target for a short-lived read-only token limited to that
 * tenant's data, and returns the URL. The caller never holds a target credential, and a session cookie of the target is never
 * used inside the frame.
 */
class EmbedSessionService
{
    public const TARGET_OPTIRADAR = 'optiradar';

    public function __construct(private readonly SubscriptionAccess $access) {}

    /**
     * @return array{0: ?array{url: string, expires_at: string, ttl_seconds: int}, 1: ?string, 2: ?string} [session, error code, message]
     */
    public function open(Application $caller, Tenant $tenant, string $target, int $ttlSeconds): array
    {
        if ($target !== self::TARGET_OPTIRADAR) {
            return [null, 'EMBED_TARGET_UNKNOWN', 'Only the optiradar target can be embedded.'];
        }

        $config = config('gateway.optiradar');
        $application = Application::query()->where('application_code', $config['application_code'])->first();
        if (! $application || ! $application->isPublished() || ! $tenant->isActive()) {
            return [null, 'EMBED_TARGET_NOT_ENTITLED', 'The tenant cannot use the embedded application.'];
        }

        foreach ([$caller, $application] as $app) {
            if (! $tenant->applications()->where('applications.id', $app->id)->wherePivot('status', 'ACTIVE')->exists() || ! $this->access->allowed($tenant, $app)) {
                return [null, 'EMBED_TARGET_NOT_ENTITLED', 'The tenant is not entitled to '.$app->application_code.'.'];
            }
        }

        if ($config['base_url'] === '' || ! $config['embed_token'] || ! $application->frontend_url) {
            return [null, 'EMBED_UNAVAILABLE', 'The embedded application is not configured for embed sessions.'];
        }

        $ttl = max(60, min($ttlSeconds, (int) $config['embed_max_ttl_seconds']));

        $response = Http::withToken($config['embed_token'])->acceptJson()->asForm()->timeout($config['timeout_seconds'])
            ->baseUrl($config['base_url'])
            ->post('/api/optinexus/embed', ['tenantId' => $tenant->id, 'ttl' => $ttl]);

        if (! $response->successful() || ! is_string($response->json('token'))) {
            Log::warning('embed.optiradar_failed', ['tenant_id' => $tenant->id, 'status' => $response->status()]);

            return [null, 'EMBED_UNAVAILABLE', 'The embedded application did not issue a session.'];
        }

        $url = rtrim($application->frontend_url, '/').'/?'.http_build_query(['embed' => 1, 'token' => $response->json('token')]);

        return [['url' => $url, 'expires_at' => now()->addSeconds($ttl)->toIso8601String(), 'ttl_seconds' => $ttl], null, null];
    }
}
