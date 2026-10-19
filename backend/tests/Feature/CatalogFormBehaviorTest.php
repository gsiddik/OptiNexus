<?php

namespace Tests\Feature;

use App\Models\Capability;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class CatalogFormBehaviorTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    #[TestWith(['customers', 'customer_code', 'CUS', 'legal_name'])]
    #[TestWith(['tenants', 'tenant_code', 'TEN', 'name'])]
    #[TestWith(['products', 'product_code', 'PRD', 'name'])]
    #[TestWith(['plans', 'plan_code', 'PLN', 'name'])]
    #[TestWith(['addons', 'addon_code', 'ADD', 'name'])]
    public function test_codes_are_generated_unique_and_cannot_be_changed(string $endpoint, string $codeField, string $prefix, string $nameField): void
    {
        $this->seedCommercialBaseline();
        $this->actingAsSuperAdmin();
        $payload = [$nameField => 'First record'];
        if ($endpoint === 'tenants') {
            $payload['customer_id'] = $this->makeCustomer()->id;
        }
        if (in_array($endpoint, ['plans', 'addons'], true)) {
            $payload['product_id'] = $this->makeProduct()->id;
        }
        if ($endpoint === 'plans') {
            $payload += ['currency' => 'IDR', 'billing_interval' => 'MONTHLY'];
        }

        $first = $this->postJson("/api/v1/{$endpoint}", $payload)->assertCreated();
        $second = $this->postJson("/api/v1/{$endpoint}", [...$payload, $codeField => 'USER-CONTROLLED'])->assertCreated();

        $code = $first->json("data.{$codeField}");
        $this->assertMatchesRegularExpression('/^'.$prefix.'-[0-9A-HJKMNP-TV-Z]{26}$/', $code);
        $this->assertNotSame($code, $second->json("data.{$codeField}"));
        $this->assertNotSame('USER-CONTROLLED', $second->json("data.{$codeField}"));
        $id = $first->json('data.id');
        foreach (['EDITED', null, ''] as $attempt) {
            $this->putJson("/api/v1/{$endpoint}/{$id}", [$codeField => $attempt, $nameField => 'Changed'])->assertUnprocessable();
            $this->assertDatabaseHas($endpoint, ['id' => $id, $codeField => $code, $nameField => 'First record']);
        }
        $this->putJson("/api/v1/{$endpoint}/{$id}", [$nameField => 'Updated name'])->assertOk()->assertJsonPath("data.{$codeField}", $code);
    }

    public function test_multiple_capabilities_are_saved_and_can_be_cleared_atomically(): void
    {
        $this->actingAsCommercialRole('PRODUCT_MANAGER');
        $product = $this->makeProduct();
        $application = $this->makeApplication();
        $product->applications()->attach($application->id, ['id' => (string) Str::uuid()]);
        $first = $this->capability($application->id, 'vehicles');
        $second = $this->capability($application->id, 'workshop');
        $url = "/api/v1/products/{$product->id}/capabilities";

        $this->putJson($url, ['capability_ids' => [$first->id, $second->id]])->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('product_capabilities', ['product_id' => $product->id, 'capability_id' => $first->id]);
        $this->assertDatabaseHas('product_capabilities', ['product_id' => $product->id, 'capability_id' => $second->id]);
        $this->putJson($url, ['capability_ids' => [$second->id]])->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseMissing('product_capabilities', ['product_id' => $product->id, 'capability_id' => $first->id]);
        $this->putJson($url, ['capability_ids' => []])->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseMissing('product_capabilities', ['product_id' => $product->id]);
    }

    public function test_capabilities_from_an_unattached_application_are_rejected_without_partial_writes(): void
    {
        $this->actingAsCommercialRole('PRODUCT_MANAGER');
        $product = $this->makeProduct();
        $application = $this->makeApplication();
        $product->applications()->attach($application->id, ['id' => (string) Str::uuid()]);
        $allowed = $this->capability($application->id, 'allowed');
        $foreign = $this->capability($this->makeApplication()->id, 'foreign');
        $product->capabilities()->attach($allowed->id, ['id' => (string) Str::uuid()]);

        $this->putJson("/api/v1/products/{$product->id}/capabilities", ['capability_ids' => [$allowed->id, $foreign->id]])->assertUnprocessable();
        $this->postJson("/api/v1/products/{$product->id}/capabilities/{$foreign->id}")->assertUnprocessable();

        $this->assertDatabaseHas('product_capabilities', ['product_id' => $product->id, 'capability_id' => $allowed->id]);
        $this->assertDatabaseMissing('product_capabilities', ['product_id' => $product->id, 'capability_id' => $foreign->id]);
    }

    public function test_detaching_an_application_removes_only_its_product_capabilities(): void
    {
        $this->actingAsCommercialRole('PRODUCT_MANAGER');
        $product = $this->makeProduct();
        $firstApp = $this->makeApplication();
        $secondApp = $this->makeApplication();
        $first = $this->capability($firstApp->id, 'first');
        $second = $this->capability($secondApp->id, 'second');
        foreach ([$firstApp, $secondApp] as $app) {
            $product->applications()->attach($app->id, ['id' => (string) Str::uuid()]);
        }
        foreach ([$first, $second] as $capability) {
            $product->capabilities()->attach($capability->id, ['id' => (string) Str::uuid()]);
        }

        $this->deleteJson("/api/v1/products/{$product->id}/applications/{$firstApp->id}")->assertOk();

        $this->assertDatabaseMissing('product_applications', ['product_id' => $product->id, 'application_id' => $firstApp->id]);
        $this->assertDatabaseMissing('product_capabilities', ['product_id' => $product->id, 'capability_id' => $first->id]);
        $this->assertDatabaseHas('product_capabilities', ['product_id' => $product->id, 'capability_id' => $second->id]);
    }

    public function test_bulk_changes_enforce_assign_and_revoke_permissions_without_partial_writes(): void
    {
        $this->seedCommercialBaseline();
        $user = $this->actingAsOperatorWith(['cgo.product.view', 'cgo.product.capability.assign']);
        $product = $this->makeProduct();
        $application = $this->makeApplication();
        $product->applications()->attach($application->id, ['id' => (string) Str::uuid()]);
        $first = $this->capability($application->id, 'first');
        $second = $this->capability($application->id, 'second');
        $url = "/api/v1/products/{$product->id}/capabilities";
        $this->putJson($url, ['capability_ids' => [$first->id]])->assertOk();

        $this->putJson($url, ['capability_ids' => [$second->id]])->assertForbidden();
        $this->assertDatabaseHas('product_capabilities', ['product_id' => $product->id, 'capability_id' => $first->id]);
        $this->assertDatabaseMissing('product_capabilities', ['product_id' => $product->id, 'capability_id' => $second->id]);

        $role = $user->roles()->first();
        $role->permissions()->detach();
        $permission = Permission::where('permission_key', 'cgo.product.view')->firstOrFail();
        $role->permissions()->attach($permission->id, ['id' => (string) Str::uuid()]);
        $access = Permission::where('permission_key', 'cgo.ecosystem.access')->firstOrFail();
        $role->permissions()->attach($access->id, ['id' => (string) Str::uuid()]);
        $this->putJson($url, ['capability_ids' => [$first->id, $second->id]])->assertForbidden();
        $this->assertDatabaseMissing('product_capabilities', ['product_id' => $product->id, 'capability_id' => $second->id]);
    }

    public function test_subscription_options_work_for_subscription_admin_and_filter_tenants_by_customer(): void
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $customer = $this->makeCustomer(['legal_name' => 'Acme', 'email' => 'private@example.test']);
        $tenant = $this->makeTenant($customer, ['name' => 'Acme Tenant']);
        $otherTenant = $this->makeTenant();

        $this->getJson('/api/v1/subscriptions/options/customers')->assertOk()
            ->assertJsonFragment(['id' => $customer->id, 'legal_name' => 'Acme', 'customer_code' => $customer->customer_code])
            ->assertJsonMissing(['email' => 'private@example.test']);
        $this->getJson('/api/v1/subscriptions/options/tenants?customer_id='.$customer->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tenant->id)->assertJsonMissing(['id' => $otherTenant->id]);
        $this->getJson('/api/v1/subscriptions/options/tenants')->assertUnprocessable();
    }

    public function test_product_options_include_only_capabilities_of_attached_applications(): void
    {
        $this->actingAsCommercialRole('PRODUCT_MANAGER');
        $product = $this->makeProduct();
        $application = $this->makeApplication();
        $product->applications()->attach($application->id, ['id' => (string) Str::uuid()]);
        $first = $this->capability($application->id, 'vehicles');
        $second = $this->capability($application->id, 'workshop');
        $foreign = $this->capability($this->makeApplication()->id, 'other');

        $this->getJson('/api/v1/products/options/applications')->assertOk()->assertJsonFragment(['id' => $application->id]);
        $this->getJson("/api/v1/products/{$product->id}/capability-options")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.application.id', $application->id)
            ->assertJsonCount(2, 'data.0.capabilities')->assertJsonFragment(['id' => $first->id])
            ->assertJsonFragment(['id' => $second->id])->assertJsonMissing(['id' => $foreign->id]);
    }

    public function test_unauthorized_operator_cannot_read_subscription_choices_or_change_product_capabilities(): void
    {
        $this->seedCommercialBaseline();
        $this->actingAsOperatorWith();
        $product = $this->makeProduct();

        $this->getJson('/api/v1/subscriptions/options/customers')->assertForbidden();
        $this->getJson('/api/v1/subscriptions/options/tenants?customer_id='.$this->makeCustomer()->id)->assertForbidden();
        $this->putJson("/api/v1/products/{$product->id}/capabilities", ['capability_ids' => []])->assertForbidden();
    }

    private function capability(string $applicationId, string $code): Capability
    {
        return Capability::create(['application_id' => $applicationId, 'type' => 'MODULE', 'code' => $code, 'name' => $code, 'status' => 'ACTIVE']);
    }
}
