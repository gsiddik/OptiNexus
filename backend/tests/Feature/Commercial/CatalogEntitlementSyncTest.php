<?php

namespace Tests\Feature\Commercial;

use App\Models\Application;
use App\Models\Capability;
use App\Models\Entitlement;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\Commercial\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\IssuesServiceAccountTokens;
use Tests\TestCase;

class CatalogEntitlementSyncTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, IssuesServiceAccountTokens, RefreshDatabase;

    private function subscribedFleet(): array
    {
        $this->freezeTime();
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $tenant = $this->makeTenant();
        $fleet = $this->makeApplication(['application_code' => 'optifleet']);
        $product = $this->makeProduct();
        $product->applications()->attach($fleet->id, ['id' => (string) Str::uuid()]);
        $plan = $this->makePlan($product);
        $this->makeFlatPrice($plan);
        $response = $this->postJson('/api/v1/subscriptions', [
            'tenant_id' => $tenant->id, 'customer_id' => $tenant->customer_id, 'plan_id' => $plan->id,
        ])->assertCreated();
        $subscription = Subscription::findOrFail($response->json('data.id'));
        $this->postJson("/api/v1/subscriptions/{$subscription->id}/activate")->assertOk();
        $this->actingAsCommercialRole('PRODUCT_MANAGER');

        return [$tenant, $fleet, $product, $plan, $subscription];
    }

    private function addModule(Application $fleet, Product $product, string $module): Capability
    {
        $capability = Capability::create([
            'application_id' => $fleet->id, 'code' => 'optifleet.'.$module,
            'name' => $module, 'type' => 'MODULE', 'status' => 'ACTIVE',
        ]);
        $product->capabilities()->attach($capability->id, ['id' => (string) Str::uuid()]);

        return $capability;
    }

    public function test_plan_edits_update_the_real_fleet_menu_permissions_and_module_entitlements_for_existing_subscribers(): void
    {
        [$tenant, $fleet, $product, $plan] = $this->subscribedFleet();
        $menus = [
            'maintenance' => ['maintenance_policy.view', 'maintenance_schedule.view', 'maintenance_request.view'],
            'work_order' => ['part_request.view'],
            'vehicle' => ['maintenance_history.view'],
            'inventory' => ['product.view', 'inventory.view', 'part_return.view', 'inventory.stock_opname', 'used_part.view'],
            'procurement' => ['purchase_order.view'],
            'partner' => ['partner.view'],
        ];
        $role = $this->makeTenantRole($tenant, [], ['role_type' => 'APPLICATION', 'application_id' => $fleet->id, 'auto_capability_permissions' => true]);
        $user = User::factory()->create(['status' => 'ACTIVE']);
        $this->attachUserToTenant($user, $tenant, $role);
        $this->grantApplicationAccess($user, $tenant, $fleet);
        $unbought = $this->addModule($fleet, $product, 'analytics');
        $this->makePermission($fleet, 'optifleet.analytics.overview.view', $unbought);
        $expected = [];
        foreach ($menus as $module => $keys) {
            $capability = $this->addModule($fleet, $product, $module);
            foreach ($keys as $key) {
                $expected[] = $this->makePermission($fleet, 'optifleet.'.$key, $capability)->permission_key;
            }
            $this->postJson("/api/v1/plans/{$plan->id}/capabilities/{$capability->id}")->assertCreated();
            $this->assertTrue(app(EntitlementService::class)->check($tenant, 'optifleet', $capability->code)['allowed']);
        }
        $this->actingAsSuperAdmin();
        $matrix = $this->getJson("/api/v1/roles/{$role->id}/permissions")->assertOk();
        $this->assertEqualsCanonicalizing($expected, array_column($matrix->json('data'), 'permission_key'));
        [$token] = $this->issueServiceAccountTokenFor($fleet->id, ['authorization.check', 'commercial.read']);
        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/authorization/effective-permissions', [
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'application_code' => 'optifleet',
        ])->assertOk()->assertJsonPath('data.allowed', true);
        $this->assertEqualsCanonicalizing($expected, array_column($response->json('data.permissions'), 'permission'));
        $commercial = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/tenants/{$tenant->id}/commercial-context")->assertOk();
        $capabilities = collect($commercial->json('data.effective_entitlements'))->where('entitlement_type', 'CAPABILITY')->whereStrict('value', true)->pluck('entitlement_key')->all();
        $this->assertEqualsCanonicalizing(['optifleet.maintenance', 'optifleet.work_order', 'optifleet.vehicle', 'optifleet.inventory', 'optifleet.procurement', 'optifleet.partner'], $capabilities);

    }

    public function test_capability_removal_reconciles_access_but_keeps_manual_denials_and_custom_roles(): void
    {
        [$tenant, $fleet, $product, $plan, $subscription] = $this->subscribedFleet();
        $module = $this->addModule($fleet, $product, 'inventory');
        $permission = $this->makePermission($fleet, 'optifleet.product.view', $module);
        $role = $this->makeTenantRole($tenant, [], ['auto_capability_permissions' => false]);
        $override = app(EntitlementService::class)->manualOverride($tenant, [
            'application_id' => $fleet->id, 'capability_id' => $module->id,
            'entitlement_type' => 'CAPABILITY', 'entitlement_key' => $module->code, 'value' => 'false',
        ], null);
        $override->update(['subscription_id' => $subscription->id]);
        $this->postJson("/api/v1/plans/{$plan->id}/capabilities/{$module->id}")->assertCreated();
        $this->assertFalse(app(EntitlementService::class)->check($tenant, 'optifleet', $module->code)['allowed']);
        $this->assertSame('ACTIVE', $override->refresh()->status);
        $automatic = $this->makeTenantRole($tenant, [], ['auto_capability_permissions' => true]);
        $this->assertFalse(app(AuthorizationService::class)->capabilityPermissions($automatic, $tenant->id)->contains('id', $permission->id));
        $this->assertFalse($role->refresh()->auto_capability_permissions);
        $this->assertFalse($role->permissions()->whereKey($permission->id)->exists());
        $this->deleteJson("/api/v1/plans/{$plan->id}/capabilities/{$module->id}")->assertOk();
        $this->assertFalse(Entitlement::query()->where('subscription_id', $subscription->id)->where('source_type', 'PLAN')
            ->where('entitlement_key', $module->code)->where('status', 'ACTIVE')->exists());
        $this->assertSame('ACTIVE', $override->refresh()->status);
    }

    public function test_limit_removal_refreshes_existing_subscription_and_preserves_addon_delta(): void
    {
        [$tenant, $fleet, $product, $plan, $subscription] = $this->subscribedFleet();
        $addon = $this->makeAddon($product);
        $subscription->items()->create(['item_type' => 'ADDON', 'addon_id' => $addon->id, 'quantity' => 1, 'unit_price' => 0, 'currency' => 'IDR', 'start_at' => now()]);
        $this->postJson("/api/v1/addons/{$addon->id}/limits", ['limit_key' => 'vehicles', 'limit_delta' => 5])->assertOk();
        $response = $this->postJson("/api/v1/plans/{$plan->id}/limits", ['limit_key' => 'vehicles', 'limit_value' => 10, 'is_unlimited' => false])->assertOk();
        $this->assertEquals(15, app(EntitlementService::class)->check($tenant, 'optifleet', 'vehicles')['value']);
        $id = $response->json('data.id');
        $this->deleteJson("/api/v1/plans/{$plan->id}/limits/{$id}")->assertOk();
        $this->assertDatabaseMissing('plan_limits', ['id' => $id]);
        $this->assertEquals(5, app(EntitlementService::class)->check($tenant, 'optifleet', 'vehicles')['value']);
        $this->getJson("/api/v1/plans/{$plan->id}")->assertOk()->assertJsonPath('data.product_name', $product->name);
    }

    public function test_refresh_command_is_preview_by_default_and_repairs_only_the_selected_tenant_without_reactivating_suspended_access(): void
    {
        [$tenant, $fleet, $product, $plan, $subscription] = $this->subscribedFleet();
        $otherTenant = $this->makeTenant();
        $otherSubscription = $subscription->replicate();
        $otherSubscription->fill(['tenant_id' => $otherTenant->id, 'customer_id' => $otherTenant->customer_id, 'subscription_number' => 'OTHER-SUB']);
        $otherSubscription->save();
        app(EntitlementService::class)->generateFromSubscription($otherSubscription);
        $module = $this->addModule($fleet, $product, 'maintenance');
        $plan->capabilities()->attach($module->id, ['id' => (string) Str::uuid()]);
        $before = Entitlement::count();
        $this->artisan('ecosystem:refresh-entitlements', ['tenant' => $tenant->tenant_code])->assertSuccessful();
        $this->assertSame($before, Entitlement::count());
        $this->artisan('ecosystem:refresh-entitlements', ['tenant' => 'MISSING'])->assertFailed();
        $this->artisan('ecosystem:refresh-entitlements', ['tenant' => $tenant->id, '--apply' => true])->assertSuccessful();
        $this->assertTrue(app(EntitlementService::class)->check($tenant, 'optifleet', $module->code)['allowed']);
        $this->assertFalse(app(EntitlementService::class)->check($otherTenant, 'optifleet', $module->code)['allowed']);
        $subscription->update(['status' => 'SUSPENDED']);
        app(EntitlementService::class)->suspendForSubscription($subscription);
        $this->artisan('ecosystem:refresh-entitlements', ['tenant' => $tenant->id, '--apply' => true])->assertSuccessful();
        $this->assertSame('SUSPENDED', $subscription->refresh()->status);
        $this->assertFalse(app(EntitlementService::class)->check($tenant, 'optifleet', $module->code)['allowed']);
        $this->assertSame(0, $subscription->entitlements()->where('status', 'ACTIVE')->count());
    }

    public function test_addon_capability_changes_reach_existing_subscribers_without_granting_expired_subscriptions(): void
    {
        [$tenant, $fleet, $product, $plan, $subscription] = $this->subscribedFleet();
        $addon = $this->makeAddon($product);
        $subscription->items()->create(['item_type' => 'ADDON', 'addon_id' => $addon->id, 'quantity' => 1, 'unit_price' => 0, 'currency' => 'IDR', 'start_at' => now()]);
        $expired = $subscription->replicate();
        $expired->fill(['status' => 'EXPIRED', 'subscription_number' => 'EXPIRED-SUB']);
        $expired->save();
        $module = $this->addModule($fleet, $product, 'partner');
        $this->postJson("/api/v1/addons/{$addon->id}/capabilities/{$module->id}")->assertCreated();
        $this->assertTrue(app(EntitlementService::class)->check($tenant, 'optifleet', $module->code)['allowed']);
        $this->assertSame(0, $expired->entitlements()->count());
        $this->deleteJson("/api/v1/addons/{$addon->id}/capabilities/{$module->id}")->assertOk();
        $this->assertFalse(app(EntitlementService::class)->check($tenant, 'optifleet', $module->code)['allowed']);
    }

    public function test_limit_removal_requires_configuration_permission_and_keeps_the_limit_on_403(): void
    {
        [, , , $plan] = $this->subscribedFleet();
        $limit = $plan->limits()->create(['limit_key' => 'vehicles', 'limit_value' => 10, 'is_unlimited' => false]);
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $this->deleteJson("/api/v1/plans/{$plan->id}/limits/{$limit->id}")->assertForbidden();
        $this->assertDatabaseHas('plan_limits', ['id' => $limit->id]);
    }
}
