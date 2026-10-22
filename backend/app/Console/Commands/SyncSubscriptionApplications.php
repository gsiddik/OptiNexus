<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Commercial\SubscriptionApplicationService;
use Illuminate\Console\Command;

class SyncSubscriptionApplications extends Command
{
    protected $signature = 'subscriptions:sync-applications';

    protected $description = 'Reconcile tenant applications from existing subscriptions without changing user roles or passwords';

    public function handle(SubscriptionApplicationService $applications): int
    {
        Tenant::whereHas('subscriptions')->eachById(fn ($tenant) => $applications->sync($tenant));
        $this->info('Subscription application assignments reconciled.');

        return self::SUCCESS;
    }
}
