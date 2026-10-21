<?php

namespace App\Console\Commands;

use App\Models\Entitlement;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Commercial\CatalogEntitlementSyncService;
use App\Services\Commercial\EntitlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Explicit repair for installs whose catalog changed before entitlement synchronization existed. */
class RefreshSubscriptionEntitlements extends Command
{
    protected $signature = 'ecosystem:refresh-entitlements {tenant : Tenant UUID or tenant code} {--apply : Rebuild entitlements from the purchased plans/add-ons}';

    protected $description = 'Preview or refresh one tenant\'s subscription entitlements without changing roles, prices or manual overrides';

    public function handle(CatalogEntitlementSyncService $sync, EntitlementService $entitlements): int
    {
        $key = (string) $this->argument('tenant');
        $tenant = Tenant::query()->where('tenant_code', $key)->first();
        if (! $tenant && Str::isUuid($key)) {
            $tenant = Tenant::query()->find($key);
        }
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }
        $query = Subscription::query()->where('tenant_id', $tenant->id)
            ->whereIn('status', [...Subscription::ACTIVE_LIKE_STATUSES, Subscription::STATUS_SUSPENDED]);
        $subscriptions = (clone $query)->with(['plan.capabilities', 'items.addon.capabilities'])->get();
        foreach ($subscriptions as $subscription) {
            $this->line($subscription->subscription_number.' | '.$subscription->status.' | '.$subscription->plan?->name);
            $codes = $subscription->plan?->capabilities->pluck('code')->all() ?? [];
            foreach ($subscription->items->where('item_type', 'ADDON') as $item) {
                $codes = array_merge($codes, $item->addon?->capabilities->pluck('code')->all() ?? []);
            }
            $this->line('Purchased capabilities: '.implode(', ', array_unique($codes)));
        }
        $effective = $entitlements->effectiveEntitlements($tenant)->where('entitlement_type', Entitlement::TYPE_CAPABILITY)->whereStrict('value', true)->pluck('entitlement_key')->all();
        $this->line('Currently effective capabilities: '.implode(', ', $effective));
        if (! $this->option('apply')) {
            $this->info('Preview only. No changes made. Use --apply to refresh the listed subscriptions. Suspended subscriptions remain suspended.');

            return self::SUCCESS;
        }
        $count = $sync->refresh($query);
        $this->info("Refreshed {$count} subscription(s). Manual overrides and role permissions were preserved. Allow up to 300 seconds for Fleet governance caches.");

        return self::SUCCESS;
    }
}
