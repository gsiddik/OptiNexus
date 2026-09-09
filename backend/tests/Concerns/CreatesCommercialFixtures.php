<?php

namespace Tests\Concerns;

use App\Models\Addon;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\ServiceAccount;
use App\Models\TaxCode;
use App\Models\User;
use Database\Seeders\CommercialPermissionSeeder;
use Database\Seeders\CommercialRoleSeeder;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Sanctum\Sanctum;

trait CreatesCommercialFixtures
{
    private bool $commercialBaselineSeeded = false;

    /**
     * Guarded against repeat calls: tests that switch the acting user
     * across several commercial roles (segregation-of-duty checks) call
     * actingAsCommercialRole() more than once per test, and re-running
     * SystemRoleSeeder's permission sync a second time against
     * already-attached pivot rows trips an unrelated Eloquent
     * updateExistingPivot() path. Seeding once per test is also just
     * correct - the baseline never changes mid-test.
     */
    protected function seedCommercialBaseline(): void
    {
        if ($this->commercialBaselineSeeded) {
            return;
        }

        $this->seedGovernanceBaseline();
        $this->seed(CommercialPermissionSeeder::class);
        $this->seed(CommercialRoleSeeder::class);

        $this->commercialBaselineSeeded = true;
    }

    /**
     * Assigns a commercial system role GLOBALLY (tenant_id null), matching
     * how CommercialRoleSeeder registers PRODUCT_MANAGER, COMMERCIAL_ADMIN,
     * SUBSCRIPTION_ADMIN, BILLING_ADMIN, BILLING_APPROVER as TYPE_SYSTEM
     * roles - a global grant satisfies both global and tenant-scoped
     * permission checks (see AuthorizationService::roleGrantIsUsable).
     */
    protected function actingAsCommercialRole(string $roleCode): User
    {
        $this->seedCommercialBaseline();

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $role = Role::query()->where('code', $roleCode)->whereNull('tenant_id')->firstOrFail();
        $user->userRoles()->create(['role_id' => $role->id, 'tenant_id' => null]);

        Sanctum::actingAs($user);

        return $user;
    }

    protected function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'product_code' => 'PROD-'.Str::upper(Str::random(8)),
            'name' => 'Test Product',
            'status' => Product::STATUS_ACTIVE,
            'currency' => 'IDR',
        ], $overrides));
    }

    protected function makePlan(Product $product, array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'product_id' => $product->id,
            'plan_code' => 'PLAN-'.Str::upper(Str::random(8)),
            'name' => 'Test Plan',
            'billing_interval' => Plan::INTERVAL_MONTHLY,
            'status' => Plan::STATUS_ACTIVE,
            'currency' => 'IDR',
            'trial_days' => 14,
        ], $overrides));
    }

    protected function makeAddon(Product $product, array $overrides = []): Addon
    {
        return Addon::create(array_merge([
            'product_id' => $product->id,
            'addon_code' => 'ADDON-'.Str::upper(Str::random(8)),
            'name' => 'Test Addon',
            'status' => Addon::STATUS_ACTIVE,
        ], $overrides));
    }

    protected function makeTaxCode(array $overrides = []): TaxCode
    {
        return TaxCode::create(array_merge([
            'tax_code' => 'TAX-'.Str::upper(Str::random(6)),
            'label' => 'Test Tax',
            'rate' => '10.0000',
            'status' => TaxCode::STATUS_ACTIVE,
            'effective_from' => now()->subYear(),
        ], $overrides));
    }

    protected function makeFlatPrice(Plan|Addon $owner, array $overrides = []): Price
    {
        return Price::create(array_merge([
            $owner instanceof Plan ? 'plan_id' : 'addon_id' => $owner->id,
            'price_type' => Price::TYPE_FLAT,
            'currency' => 'IDR',
            'unit_amount' => '1000000.00',
            'billing_interval' => Plan::INTERVAL_MONTHLY,
            'status' => Price::STATUS_ACTIVE,
            'approval_status' => Price::APPROVAL_APPROVED,
            'effective_from' => now()->subDay(),
        ], $overrides));
    }

    protected function makeUsagePrice(Plan|Addon $owner, array $overrides = []): Price
    {
        return Price::create(array_merge([
            $owner instanceof Plan ? 'plan_id' : 'addon_id' => $owner->id,
            'price_type' => Price::TYPE_PER_VEHICLE,
            'currency' => 'IDR',
            'unit_amount' => '30000.00',
            'unit_name' => 'vehicle',
            'meter_key' => 'vehicles',
            'included_quantity' => '100.0000',
            'billing_interval' => Plan::INTERVAL_MONTHLY,
            'status' => Price::STATUS_ACTIVE,
            'approval_status' => Price::APPROVAL_APPROVED,
            'effective_from' => now()->subDay(),
        ], $overrides));
    }

    protected function issueServiceAccountToken(?string $applicationId, array $scopes): string
    {
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient('test-commercial-'.Str::random(6));

        ServiceAccount::create([
            'application_id' => $applicationId,
            'oauth_client_id' => $client->id,
            'name' => 'test-commercial-integration',
            'status' => ServiceAccount::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'scope' => implode(' ', $scopes),
        ])->assertStatus(200);

        return $response->json('access_token');
    }
}
