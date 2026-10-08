<?php

namespace App\Jobs;

use App\Models\OidcLogoutDelivery;
use App\Services\Oidc\OidcKeyService;
use App\Services\Oidc\SessionRevocationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Calls an application's back-channel logout endpoint (OIDC Back-Channel
 * Logout 1.0): POST application/x-www-form-urlencoded `logout_token=<JWT>`.
 *
 * The URL is set by an administrator on the OIDC client, not by tenants, so
 * private-network addresses are allowed (same reasoning as the gateway).
 * Redirects are never followed. The token is signed on every attempt so a
 * retry minutes later still carries a fresh `iat`.
 *
 * A 2xx answer ends it. 4xx other than 408 and 429 means the application
 * understood the call and refused it, so repeating it cannot help and the
 * delivery is marked FAILED at once. Anything else is retried with backoff.
 */
class DeliverBackchannelLogout implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public array $backoff = [10, 30, 120, 600, 1800];

    public function __construct(public string $deliveryId) {}

    public function handle(OidcKeyService $keys): void
    {
        $delivery = OidcLogoutDelivery::query()->with(['client', 'user'])->find($this->deliveryId);

        if (! $delivery || $delivery->status !== OidcLogoutDelivery::STATUS_PENDING) {
            return;
        }

        $client = $delivery->client;
        if (! $client || ! $client->isActive() || ! $client->backchannel_logout_uri || ! $delivery->user) {
            $delivery->update(['status' => OidcLogoutDelivery::STATUS_FAILED, 'last_error' => 'The application has no usable back-channel logout endpoint.']);

            return;
        }

        $delivery->increment('attempts');

        try {
            $response = Http::asForm()
                ->withOptions(['allow_redirects' => false])
                ->timeout((int) config('oidc.backchannel_timeout_seconds'))
                ->post($client->backchannel_logout_uri, ['logout_token' => $keys->sign($this->claims($delivery))]);
        } catch (ConnectionException $e) {
            $delivery->update(['last_http_status' => null, 'last_error' => Str::limit($e->getMessage(), 500)]);

            throw $e;
        }

        $status = $response->status();

        if ($response->successful()) {
            $delivery->update(['status' => OidcLogoutDelivery::STATUS_DELIVERED, 'delivered_at' => now(), 'last_http_status' => $status, 'last_error' => null]);

            return;
        }

        $delivery->update(['last_http_status' => $status, 'last_error' => Str::limit($response->body(), 500)]);

        if ($status >= 400 && $status < 500 && ! in_array($status, [408, 429], true)) {
            $delivery->update(['status' => OidcLogoutDelivery::STATUS_FAILED]);

            return;
        }

        throw new RuntimeException("Back-channel logout endpoint answered HTTP {$status}.");
    }

    public function failed(Throwable $e): void
    {
        OidcLogoutDelivery::query()->whereKey($this->deliveryId)
            ->where('status', OidcLogoutDelivery::STATUS_PENDING)
            ->update(['status' => OidcLogoutDelivery::STATUS_FAILED, 'last_error' => Str::limit($e->getMessage(), 500)]);
    }

    private function claims(OidcLogoutDelivery $delivery): array
    {
        $now = time();
        $events = [SessionRevocationService::EVENT_BACKCHANNEL_LOGOUT => new \stdClass];

        if ($delivery->type === OidcLogoutDelivery::TYPE_ACCESS_REVOKED) {
            $events[SessionRevocationService::EVENT_ACCESS_REVOKED] = array_filter([
                'reason' => $delivery->reason,
                'scope' => $delivery->tenant_id ? 'tenant' : 'user',
                'tenant_id' => $delivery->tenant_id,
            ], fn ($value) => $value !== null);
        }

        return [
            'iss' => config('oidc.issuer'),
            'aud' => $delivery->client->client_id,
            'iat' => $now,
            'exp' => $now + (int) config('oidc.logout_token_ttl_seconds'),
            'jti' => (string) Str::uuid(),
            'sub' => $delivery->user_id,
            // Applications that match accounts by email (OptiRadar) need it; others use `sub`.
            'email' => $delivery->user->email,
            'events' => $events,
        ];
    }
}
