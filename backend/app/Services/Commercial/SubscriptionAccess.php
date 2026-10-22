<?php

namespace App\Services\Commercial;

use App\Models\Application;
use App\Models\Subscription;
use App\Models\Tenant;

class SubscriptionAccess
{
    public function required(Application $application): bool
    {
        return in_array($application->application_code, ['optifleet', 'optiaccounting', 'optientry'], true);
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
