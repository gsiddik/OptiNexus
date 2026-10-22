<?php

namespace App\Services\Commercial;

use App\Models\Application;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Oidc\SessionRevocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionApplicationService
{
    public function applicationIds(Subscription $subscription): array
    {
        $ids = $subscription->product->applications()->pluck('applications.id');
        $ids = $ids->merge($subscription->plan->capabilities()->pluck('application_id'));
        foreach ($subscription->items()->where('item_type', 'ADDON')->inForce()->with('addon.product.applications', 'addon.capabilities')->get() as $item) {
            if ($item->addon) {
                $ids = $ids->merge($item->addon->product->applications->pluck('id'))->merge($item->addon->capabilities->pluck('application_id'));
            }
        }
        $ids = $ids->filter()->unique()->values()->all();
        $selected = $subscription->metadata['application_ids'] ?? null;

        return is_array($selected) ? array_values(array_intersect($ids, $selected)) : $ids;
    }

    /** Reconcile the union: changing one subscription never removes an app supplied by another. */
    public function sync(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant): void {
            $tenant = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $ids = $tenant->subscriptions()->whereNotIn('status', ['CANCELLED', 'TERMINATED'])
                ->get()->flatMap(fn ($subscription) => $this->applicationIds($subscription))->unique()->all();
            $old = $tenant->applications()->pluck('applications.id')->all();
            foreach (array_diff($old, $ids) as $id) {
                $application = Application::find($id);
                if ($application) {
                    app(SessionRevocationService::class)->revokeTenant($tenant, 'subscription_application_removed', $application);
                }
            }
            $rows = [];
            foreach ($ids as $id) {
                $rows[$id] = ['status' => 'ACTIVE'];
                if (! in_array($id, $old, true)) {
                    $rows[$id]['id'] = (string) Str::uuid();
                }
            }
            $tenant->applications()->sync($rows);
        });
    }
}
