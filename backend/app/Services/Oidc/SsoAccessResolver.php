<?php

namespace App\Services\Oidc;

use App\Models\Application;
use App\Models\OidcClient;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use Illuminate\Support\Collection;

/**
 * Decides whether a user may sign in to an application for a tenant. This is
 * the server-side gate behind "a user subscribed to telematics can open
 * OptiRadar": the tenant must be assigned the application (its subscription
 * outcome) and the user must hold active access to it in that tenant.
 */
class SsoAccessResolver
{
    /**
     * Active tenants in which the user may open the application.
     *
     * @return Collection<int, Tenant>
     */
    public function eligibleTenants(User $user, Application $application): Collection
    {
        if (! $user->isActive() || ! $this->applicationIsLive($application)) {
            return collect();
        }

        return Tenant::query()
            ->where('status', Tenant::STATUS_ACTIVE)
            ->whereIn('id', TenantMembership::query()
                ->where('user_id', $user->id)
                ->where('status', TenantMembership::STATUS_ACTIVE)
                ->select('tenant_id'))
            ->whereIn('id', UserApplicationAccess::query()
                ->where('user_id', $user->id)
                ->where('application_id', $application->id)
                ->where('status', UserApplicationAccess::STATUS_ACTIVE)
                ->select('tenant_id'))
            ->whereHas('applications', fn ($q) => $q
                ->where('applications.id', $application->id)
                ->where('tenant_applications.status', 'ACTIVE'))
            ->orderBy('name')
            ->get();
    }

    public function canAccess(User $user, Application $application, Tenant $tenant): bool
    {
        return $this->eligibleTenants($user, $application)->contains('id', $tenant->id);
    }

    /**
     * Every application the user may open in the tenant, for app switchers
     * (the `apps` and `groups` claims).
     *
     * @return array<int, array{code: string, name: string, launch_url: ?string}>
     */
    public function launchableApplications(User $user, Tenant $tenant): array
    {
        if (! $user->isActive() || ! $tenant->isActive()) {
            return [];
        }

        $applicationIds = UserApplicationAccess::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->where('status', UserApplicationAccess::STATUS_ACTIVE)
            ->pluck('application_id');

        $membershipActive = TenantMembership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->exists();

        if (! $membershipActive) {
            return [];
        }

        return $tenant->applications()
            ->wherePivot('status', 'ACTIVE')
            ->whereIn('applications.id', $applicationIds)
            ->get()
            ->filter(fn (Application $app) => $this->applicationIsLive($app))
            ->map(fn (Application $app) => [
                'code' => $app->application_code,
                'name' => $app->name,
                'launch_url' => OidcClient::query()
                    ->where('application_id', $app->id)
                    ->where('status', OidcClient::STATUS_ACTIVE)
                    ->value('launch_url') ?? $app->frontend_url,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function applicationIsLive(Application $application): bool
    {
        return in_array($application->status, [Application::STATUS_PUBLISHED, Application::STATUS_APPROVED], true);
    }
}
