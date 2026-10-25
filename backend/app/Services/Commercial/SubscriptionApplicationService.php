<?php

namespace App\Services\Commercial;

use App\Models\Application;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Event\EventDispatchService;
use App\Services\Event\PlatformEvents;
use App\Services\Oidc\SessionRevocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionApplicationService
{
    public function applicationIds(Subscription $subscription): array
    {
        $ids = ($subscription->product?->applications()->pluck('applications.id') ?? collect());
        $ids = $ids->merge(($subscription->plan?->capabilities()->pluck('application_id') ?? collect()));
        foreach ($subscription->items()->where('item_type', 'ADDON')->inForce()->with('addon.product.applications', 'addon.capabilities')->get() as $item) {
            if ($item->addon) {
                $ids = $ids->merge(($item->addon->product?->applications->pluck('id') ?? collect()))->merge($item->addon->capabilities->pluck('application_id'));
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

            // Told after the commit, so a receiver that calls back sees the assignment. Never part of the subscription change itself.
            $assigned = array_values(array_diff($ids, $old));
            $removed = array_values(array_diff($old, $ids));
            DB::afterCommit(fn () => $this->announce($tenant, $assigned, $removed));
        });
    }

    /**
     * @param  array<int, string>  $assigned  application ids newly assigned to the tenant
     * @param  array<int, string>  $removed  application ids no longer assigned
     */
    private function announce(Tenant $tenant, array $assigned, array $removed): void
    {
        foreach ([PlatformEvents::TENANT_APPLICATION_ASSIGNED => $assigned, PlatformEvents::TENANT_APPLICATION_REMOVED => $removed] as $key => $ids) {
            if ($ids === []) {
                continue;
            }

            try {
                foreach (Application::query()->whereIn('id', $ids)->get() as $application) {
                    // A savepoint of its own: this runs after the commit but possibly inside the caller's outer transaction, and a failed
                    // statement would otherwise abort that whole transaction (PostgreSQL) and take the caller's work with it.
                    DB::transaction(fn () => app(EventDispatchService::class)->publishPlatform($key, $tenant, [
                        'aggregate_type' => 'tenant',
                        'aggregate_id' => $tenant->id,
                        'payload' => [
                            'application_code' => $application->application_code,
                            'application_name' => $application->name,
                            'tenant_code' => $tenant->tenant_code,
                            'tenant_name' => $tenant->name,
                        ],
                    ]));
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
