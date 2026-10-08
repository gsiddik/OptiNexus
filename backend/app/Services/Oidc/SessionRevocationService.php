<?php

namespace App\Services\Oidc;

use App\Jobs\DeliverBackchannelLogout;
use App\Models\Application;
use App\Models\OidcAccessToken;
use App\Models\OidcClient;
use App\Models\OidcLogoutDelivery;
use App\Models\OidcSession;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Central logout and automatic deactivation.
 *
 * Two things can happen to a user's application sessions:
 *  - LOGOUT: the user signed out somewhere, so every application session of
 *    that user ends everywhere (all devices).
 *  - ACCESS_REVOKED: OptiNexus no longer lets the user into an application
 *    (user suspended or disabled, removed from the tenant, application access
 *    removed, tenant suspended). Sessions end and the application is also told
 *    to deactivate its own account for the user, which lets it block password
 *    login as well.
 *
 * Both are delivered as OIDC Back-Channel Logout calls through an outbox
 * (oidc_logout_deliveries) with retries; see DeliverBackchannelLogout.
 */
class SessionRevocationService
{
    public const EVENT_BACKCHANNEL_LOGOUT = 'http://schemas.openid.net/event/backchannel-logout';

    /** Custom event carried next to the standard one when the account must be deactivated. */
    public const EVENT_ACCESS_REVOKED = 'https://schemas.optinexus.io/event/access-revoked';

    public function __construct(
        private readonly SsoAccessResolver $access,
        private readonly AuditService $audit,
    ) {}

    /**
     * End every application session of the user and notify the applications.
     * Also closes the user's OptiNexus own sessions and API tokens.
     *
     * @return int Number of applications notified.
     */
    public function logoutEverywhere(User $user, string $reason = 'logout', ?Request $request = null): int
    {
        return $this->end($user, $reason, OidcLogoutDelivery::TYPE_LOGOUT, null, null, $request);
    }

    /**
     * Take access away. Pass a tenant and/or an application to narrow it; with
     * neither, the user is cut off everywhere (OptiNexus sessions included).
     * Call this before deleting the access rows, because they decide which
     * applications have to be told.
     *
     * @return int Number of applications notified.
     */
    public function revokeAccess(User $user, string $reason, ?Tenant $tenant = null, ?Application $application = null, ?Request $request = null): int
    {
        return $this->end($user, $reason, OidcLogoutDelivery::TYPE_ACCESS_REVOKED, $tenant, $application, $request);
    }

    /**
     * Take access away from everyone in a tenant (suspended, terminated, or an
     * application unassigned from it).
     *
     * @return int Number of notifications queued.
     */
    public function revokeTenant(Tenant $tenant, string $reason, ?Application $application = null, ?Request $request = null): int
    {
        $userIds = UserApplicationAccess::query()->where('tenant_id', $tenant->id)
            ->when($application, fn ($q) => $q->where('application_id', $application->id))
            ->pluck('user_id')
            ->merge(OidcSession::query()->active()->where('tenant_id', $tenant->id)->pluck('user_id'))
            ->unique();

        $count = 0;
        User::query()->whereIn('id', $userIds)->each(function (User $user) use (&$count, $tenant, $application, $reason, $request) {
            $count += $this->revokeAccess($user, $reason, $tenant, $application, $request);
        });

        return $count;
    }

    /**
     * Safety net for changes that have no hook (an expired subscription, a
     * direct database edit): end every active session whose access no longer
     * holds and notify the application.
     *
     * @return int Number of sessions ended.
     */
    public function reconcile(): int
    {
        $ended = 0;

        OidcSession::query()->active()->with(['user', 'tenant', 'client.application'])
            ->orderBy('created_at')
            ->each(function (OidcSession $session) use (&$ended) {
                if (! $session->user || ! $session->tenant || ! $session->client?->application) {
                    return;
                }

                $reason = $this->access->denialReason($session->user, $session->client->application, $session->tenant);
                if ($reason === null) {
                    return;
                }

                $this->revokeAccess($session->user, $reason, $session->tenant, $session->client->application);
                $ended++;
            });

        return $ended;
    }

    private function end(User $user, string $reason, string $type, ?Tenant $tenant, ?Application $application, ?Request $request): int
    {
        $deliveryIds = DB::transaction(function () use ($user, $reason, $type, $tenant, $application) {
            $applicationClients = $application
                ? OidcClient::query()->where('application_id', $application->id)->pluck('id')
                : null;

            $sessions = OidcSession::query()->active()->where('user_id', $user->id)
                ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
                ->when($applicationClients, fn ($q) => $q->whereIn('oidc_client_id', $applicationClients))
                ->get();

            $clientIds = $sessions->pluck('oidc_client_id');

            if ($type === OidcLogoutDelivery::TYPE_ACCESS_REVOKED) {
                // The account may exist in an application the user never signed in to by SSO.
                $accessApplications = UserApplicationAccess::query()->where('user_id', $user->id)
                    ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
                    ->when($application, fn ($q) => $q->where('application_id', $application->id))
                    ->pluck('application_id');

                $clientIds = $clientIds->merge(
                    OidcClient::query()->whereIn('application_id', $accessApplications)->pluck('id')
                );
            }

            OidcSession::query()->whereIn('id', $sessions->pluck('id'))
                ->update(['ended_at' => now(), 'end_reason' => $reason]);

            OidcAccessToken::query()->where('user_id', $user->id)->whereNull('revoked_at')
                ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
                ->when($applicationClients, fn ($q) => $q->whereIn('oidc_client_id', $applicationClients))
                ->update(['revoked_at' => now()]);

            return OidcClient::query()
                ->whereIn('id', $clientIds->unique())
                ->where('status', OidcClient::STATUS_ACTIVE)
                ->whereNotNull('backchannel_logout_uri')
                ->get()
                ->map(fn (OidcClient $client) => OidcLogoutDelivery::create([
                    'oidc_client_id' => $client->id,
                    'user_id' => $user->id,
                    'tenant_id' => $type === OidcLogoutDelivery::TYPE_ACCESS_REVOKED ? $tenant?->id : null,
                    'type' => $type,
                    'reason' => $reason,
                    'status' => OidcLogoutDelivery::STATUS_PENDING,
                ])->id)
                ->all();
        });

        if ($tenant === null && $application === null) {
            $this->closeOptiNexusSessions($user);
        }

        $this->audit->record(
            $type === OidcLogoutDelivery::TYPE_LOGOUT ? 'user.sessions_ended' : 'user.access_revoked',
            $request,
            tenantId: $tenant?->id,
            applicationId: $application?->id,
            resourceType: 'User',
            resourceId: $user->id,
            newValue: ['reason' => $reason, 'applications_notified' => count($deliveryIds)],
        );

        foreach ($deliveryIds as $id) {
            try {
                DeliverBackchannelLogout::dispatch($id);
            } catch (\Throwable $e) {
                // With the sync queue a failing application would otherwise break the admin's request.
                report($e);
            }
        }

        return count($deliveryIds);
    }

    private function closeOptiNexusSessions(User $user): void
    {
        $user->tokens()->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
