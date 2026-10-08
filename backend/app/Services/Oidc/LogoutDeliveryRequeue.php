<?php

namespace App\Services\Oidc;

use App\Jobs\DeliverBackchannelLogout;
use App\Models\OidcLogoutDelivery;
use App\Models\OidcSession;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Services\AuditService;
use Throwable;

/**
 * Sends Back-Channel Logout calls again that ended as FAILED (the application
 * was down for longer than the job retries, or answered an error that has
 * been fixed since).
 *
 * A call is only repeated while it is still true and harmless, because an
 * application acts on it the moment it arrives:
 *  - LOGOUT: not when the user signed in to that application again after the
 *    call was created (it would end the new session), and not when the call is
 *    older than $maxAgeHours (the user may have signed in to the application
 *    directly with a password since, a session OptiNexus does not know).
 *  - ACCESS_REVOKED: only while access is still denied. A user who was
 *    reinstated must not have the application account switched off by an old
 *    call. Age does not matter here, the current state decides.
 *  - Applications that no longer have a usable endpoint are skipped.
 */
class LogoutDeliveryRequeue
{
    public function __construct(
        private readonly SsoAccessResolver $access,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{requeued: int, skipped_signed_in_again: int, skipped_too_old: int, skipped_access_restored: int, skipped_no_endpoint: int}
     */
    public function requeue(?string $clientId = null, ?string $userId = null, int $maxAgeHours = 24, bool $dryRun = false): array
    {
        $result = ['requeued' => 0, 'skipped_signed_in_again' => 0, 'skipped_too_old' => 0, 'skipped_access_restored' => 0, 'skipped_no_endpoint' => 0];
        $ids = [];

        OidcLogoutDelivery::query()
            ->where('status', OidcLogoutDelivery::STATUS_FAILED)
            ->when($clientId, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('client_id', $clientId)))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->with(['client.application', 'user', 'tenant'])
            ->orderBy('created_at')
            ->each(function (OidcLogoutDelivery $delivery) use (&$result, &$ids, $maxAgeHours) {
                $skip = $this->reasonToSkip($delivery, $maxAgeHours);

                if ($skip !== null) {
                    $result['skipped_'.$skip]++;

                    return;
                }

                $result['requeued']++;
                $ids[] = $delivery->id;
            });

        if ($dryRun || $ids === []) {
            return $result;
        }

        OidcLogoutDelivery::query()->whereIn('id', $ids)
            ->update(['status' => OidcLogoutDelivery::STATUS_PENDING, 'attempts' => 0, 'last_http_status' => null]);

        $this->audit->record('oidc.logout_deliveries_requeued', null, newValue: $result);

        foreach ($ids as $id) {
            try {
                DeliverBackchannelLogout::dispatch($id);
            } catch (Throwable $e) {
                // With the sync queue a failing application must not stop the others.
                report($e);
            }
        }

        return $result;
    }

    /**
     * @return string|null signed_in_again|too_old|access_restored|no_endpoint, or null when it may be sent again
     */
    private function reasonToSkip(OidcLogoutDelivery $delivery, int $maxAgeHours): ?string
    {
        $client = $delivery->client;
        $user = $delivery->user;

        if (! $client || ! $client->isActive() || ! $client->backchannel_logout_uri || ! $user) {
            return 'no_endpoint';
        }

        if ($delivery->type === OidcLogoutDelivery::TYPE_LOGOUT) {
            if ($delivery->created_at->lt(now()->subHours($maxAgeHours))) {
                return 'too_old';
            }

            $signedInAgain = OidcSession::query()
                ->where('user_id', $user->id)
                ->where('oidc_client_id', $client->id)
                ->where('created_at', '>', $delivery->created_at)
                ->exists();

            return $signedInAgain ? 'signed_in_again' : null;
        }

        return $this->stillDenied($delivery, $user) ? null : 'access_restored';
    }

    private function stillDenied(OidcLogoutDelivery $delivery, User $user): bool
    {
        $application = $delivery->client->application;

        if ($delivery->tenant_id && $delivery->tenant && $application) {
            return $this->access->denialReason($user, $application, $delivery->tenant) !== null;
        }

        if ($delivery->reason === 'application_access_revoked' && $application) {
            return ! UserApplicationAccess::query()
                ->where('user_id', $user->id)
                ->where('application_id', $application->id)
                ->where('status', UserApplicationAccess::STATUS_ACTIVE)
                ->exists();
        }

        return ! $user->isActive();
    }
}
