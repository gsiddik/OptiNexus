<?php

namespace App\Services\Commercial;

use App\Models\Application;
use App\Models\Entitlement;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Generates and resolves Entitlements - what a tenant can actually use -
 * from the commercial state (active Subscription + Plan + Addons) plus any
 * manual overrides. Deliberately independent of Role/Permission (RBAC):
 * Subscription answers "what was purchased", Entitlement answers "what is
 * enabled", Permission answers "what may this user do". See
 * AuthorizationService for the separate permission-resolution path.
 *
 * History is preserved: regeneration never deletes rows, it revokes the
 * old ones (status + effective_until) and inserts fresh ones.
 */
class EntitlementService
{
    /**
     * (Re)generates PLAN/ADDON-sourced entitlements for a subscription from
     * its current plan, the plan's product applications, and its ADDON
     * subscription items. Must run inside the caller's transaction.
     */
    public function generateFromSubscription(Subscription $subscription): void
    {
        $this->revokeSourcedEntitlements($subscription, 'plan_change');

        $plan = $subscription->plan()->with(['capabilities.application', 'limits', 'product.applications'])->first();
        if (! $plan) {
            return;
        }

        foreach ($plan->product->applications as $application) {
            $this->grant($subscription, Entitlement::TYPE_APPLICATION, $application->application_code, 'true', Entitlement::SOURCE_PLAN, $plan->id, $application->id, null);
        }

        foreach ($plan->capabilities as $capability) {
            $this->grant($subscription, Entitlement::TYPE_CAPABILITY, $capability->code, 'true', Entitlement::SOURCE_PLAN, $plan->id, $capability->application_id, $capability->id);
        }

        foreach ($plan->limits as $limit) {
            $value = $limit->is_unlimited ? 'unlimited' : (string) $limit->limit_value;
            $this->grant($subscription, Entitlement::TYPE_LIMIT, $limit->limit_key, $value, Entitlement::SOURCE_PLAN, $plan->id, null, null);
        }

        foreach ($subscription->items()->where('item_type', 'ADDON')->with('addon.capabilities.application', 'addon.limits')->get() as $item) {
            $addon = $item->addon;
            if (! $addon) {
                continue;
            }

            foreach ($addon->capabilities as $capability) {
                $this->grant($subscription, Entitlement::TYPE_CAPABILITY, $capability->code, 'true', Entitlement::SOURCE_ADDON, $addon->id, $capability->application_id, $capability->id);
            }

            foreach ($addon->limits as $limit) {
                // Addon limits are deltas (e.g. "+50 vehicles"), summed with
                // the plan's base limit during effective resolution.
                $this->grant($subscription, Entitlement::TYPE_LIMIT, $limit->limit_key, (string) $limit->limit_delta, Entitlement::SOURCE_ADDON, $addon->id, null, null);
            }
        }
    }

    public function suspendForSubscription(Subscription $subscription): void
    {
        Entitlement::query()->where('subscription_id', $subscription->id)->where('status', Entitlement::STATUS_ACTIVE)
            ->update(['status' => Entitlement::STATUS_SUSPENDED, 'updated_at' => now()]);
    }

    public function restoreForSubscription(Subscription $subscription): void
    {
        Entitlement::query()->where('subscription_id', $subscription->id)->where('status', Entitlement::STATUS_SUSPENDED)
            ->update(['status' => Entitlement::STATUS_ACTIVE, 'updated_at' => now()]);
    }

    public function expireForSubscription(Subscription $subscription): void
    {
        Entitlement::query()->where('subscription_id', $subscription->id)->whereIn('status', [Entitlement::STATUS_ACTIVE, Entitlement::STATUS_SUSPENDED])
            ->update(['status' => Entitlement::STATUS_EXPIRED, 'effective_until' => now(), 'updated_at' => now()]);
    }

    public function revokeSourcedEntitlements(Subscription $subscription, string $reason): void
    {
        Entitlement::query()->where('subscription_id', $subscription->id)
            ->whereIn('status', [Entitlement::STATUS_ACTIVE, Entitlement::STATUS_SUSPENDED])
            ->update(['status' => Entitlement::STATUS_REVOKED, 'effective_until' => now(), 'reason' => $reason, 'updated_at' => now()]);
    }

    public function manualOverride(Tenant $tenant, array $data, ?User $actor): Entitlement
    {
        return Entitlement::create([
            'tenant_id' => $tenant->id,
            'subscription_id' => null,
            'application_id' => $data['application_id'] ?? null,
            'capability_id' => $data['capability_id'] ?? null,
            'entitlement_type' => $data['entitlement_type'],
            'entitlement_key' => $data['entitlement_key'],
            'value' => (string) $data['value'],
            'status' => Entitlement::STATUS_ACTIVE,
            'source_type' => Entitlement::SOURCE_MANUAL_OVERRIDE,
            'effective_from' => $data['effective_from'] ?? now(),
            'effective_until' => $data['effective_until'] ?? null,
            'created_by' => $actor?->id,
            'reason' => $data['reason'] ?? null,
        ]);
    }

    /**
     * @return Collection<int, array{entitlement_key:string, entitlement_type:string, value:mixed, source:string}>
     */
    public function effectiveEntitlements(Tenant $tenant, ?string $applicationCode = null): Collection
    {
        $applicationId = $applicationCode ? Application::query()->where('application_code', $applicationCode)->value('id') : null;

        $query = Entitlement::query()->where('tenant_id', $tenant->id)->where('status', Entitlement::STATUS_ACTIVE)
            ->where('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()));

        if ($applicationCode) {
            $query->where(fn ($q) => $q->where('application_id', $applicationId)->orWhereNull('application_id'));
        }

        $rows = $query->get();

        $groups = $rows->groupBy(fn (Entitlement $e) => $e->entitlement_type.':'.$e->entitlement_key);

        return $groups->map(function (Collection $group, string $key) {
            [$type] = explode(':', $key, 2);
            $entitlementKey = $group->first()->entitlement_key;

            $override = $group->firstWhere('source_type', Entitlement::SOURCE_MANUAL_OVERRIDE);
            if ($override) {
                return [
                    'entitlement_type' => $type,
                    'entitlement_key' => $entitlementKey,
                    'value' => $override->decodedValue(),
                    'source' => Entitlement::SOURCE_MANUAL_OVERRIDE,
                ];
            }

            if ($type === Entitlement::TYPE_LIMIT) {
                if ($group->contains(fn (Entitlement $e) => $e->decodedValue() === INF)) {
                    $value = INF;
                } else {
                    $value = $group->sum(fn (Entitlement $e) => (float) $e->decodedValue());
                }

                $source = $group->contains('source_type', Entitlement::SOURCE_PLAN) ? Entitlement::SOURCE_PLAN : ($group->first()->source_type ?? Entitlement::SOURCE_SYSTEM);
                if ($group->pluck('source_type')->unique()->count() > 1) {
                    $source = Entitlement::SOURCE_PLAN;
                }

                return ['entitlement_type' => $type, 'entitlement_key' => $entitlementKey, 'value' => $value === INF ? 'unlimited' : $value, 'source' => $source];
            }

            $granted = $group->contains(fn (Entitlement $e) => $e->decodedValue() === true);
            $source = $group->contains('source_type', Entitlement::SOURCE_PLAN) ? Entitlement::SOURCE_PLAN : ($group->first()->source_type ?? Entitlement::SOURCE_SYSTEM);

            return ['entitlement_type' => $type, 'entitlement_key' => $entitlementKey, 'value' => $granted, 'source' => $source];
        })->values();
    }

    public function check(Tenant $tenant, string $applicationCode, string $entitlementKey): array
    {
        $effective = $this->effectiveEntitlements($tenant, $applicationCode);
        $match = $effective->first(fn ($row) => $row['entitlement_key'] === $entitlementKey);

        if (! $match) {
            return ['allowed' => false, 'entitlement_key' => $entitlementKey, 'value' => false, 'source' => null];
        }

        $allowed = match (true) {
            is_bool($match['value']) => $match['value'],
            is_numeric($match['value']) => (float) $match['value'] > 0,
            default => $match['value'] !== '' && $match['value'] !== null,
        };

        return ['allowed' => $allowed, 'entitlement_key' => $entitlementKey, 'value' => $match['value'], 'source' => $match['source']];
    }

    private function grant(Subscription $subscription, string $type, string $key, string $value, string $sourceType, string $sourceReferenceId, ?string $applicationId, ?string $capabilityId): void
    {
        Entitlement::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'application_id' => $applicationId,
            'capability_id' => $capabilityId,
            'entitlement_type' => $type,
            'entitlement_key' => $key,
            'value' => $value,
            'status' => Entitlement::STATUS_ACTIVE,
            'source_type' => $sourceType,
            'source_reference_id' => $sourceReferenceId,
            'effective_from' => now(),
        ]);
    }
}
