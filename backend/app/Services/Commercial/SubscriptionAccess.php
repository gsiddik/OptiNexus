<?php

namespace App\Services\Commercial;

use App\Models\Application;
use App\Models\Capability;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class SubscriptionAccess
{
    /**
     * Whether a tenant needs an active subscription to use the application. An explicit `subscription_required` wins; when it
     * is NULL the catalog decides: an application sold through a product, or whose capabilities are in a plan or add-on, is
     * required. Never decided by application code.
     */
    public function required(Application $application): bool
    {
        if ($application->subscription_required !== null) {
            return (bool) $application->subscription_required;
        }

        return $application->products()->exists()
            || Capability::query()->where('application_id', $application->id)
                ->where(fn ($q) => $q->whereIn('id', DB::table('plan_capabilities')->select('capability_id'))
                    ->orWhereIn('id', DB::table('addon_capabilities')->select('capability_id')))->exists();
    }

    public function allowed(Tenant $tenant, Application $application): bool
    {
        return ! $this->required($application) || $tenant->subscriptions()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('current_period_start')->orWhere('current_period_start', '<=', now()))
            ->where('current_period_end', '>', now())
            ->get()->contains(fn ($subscription) => in_array($application->id, app(SubscriptionApplicationService::class)->applicationIds($subscription), true));
    }
}
