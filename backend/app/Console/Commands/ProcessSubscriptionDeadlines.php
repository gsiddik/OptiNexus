<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Commercial\SubscriptionLifecycleService;
use Illuminate\Console\Command;

class ProcessSubscriptionDeadlines extends Command
{
    protected $signature = 'subscriptions:process-deadlines';

    protected $description = 'Expire elapsed subscription periods and suspend expired grace windows';

    public function handle(SubscriptionLifecycleService $lifecycle): int
    {
        Subscription::query()->whereIn('status', [...Subscription::ACTIVE_LIKE_STATUSES, Subscription::STATUS_EXPIRED])
            ->where(fn ($q) => $q->where('current_period_end', '<=', now())->orWhere('grace_end_at', '<=', now()))
            ->eachById(fn (Subscription $subscription) => $lifecycle->processDue($subscription));

        return self::SUCCESS;
    }
}
