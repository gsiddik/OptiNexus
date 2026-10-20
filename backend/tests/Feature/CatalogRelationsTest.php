<?php

namespace Tests\Feature;

use App\Models\Capability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class CatalogRelationsTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCommercialBaseline();
    }

    public function test_plan_and_addon_capabilities_are_limited_to_parent_product_choices(): void
    {
        $this->actingAsSuperAdmin();
        $product = $this->makeProduct();
        $app = $this->makeApplication();
        $chosen = Capability::create(['application_id' => $app->id, 'type' => 'MODULE', 'name' => 'Chosen', 'code' => 'chosen', 'status' => 'ACTIVE']);
        $excluded = Capability::create(['application_id' => $app->id, 'type' => 'MODULE', 'name' => 'Excluded', 'code' => 'excluded', 'status' => 'ACTIVE']);
        $product->capabilities()->attach($chosen->id, ['id' => (string) Str::uuid()]);
        foreach (['plans' => $this->makePlan($product), 'addons' => $this->makeAddon($product)] as $path => $owner) {
            $this->getJson("/api/v1/{$path}/{$owner->id}/capability-options")->assertOk()->assertJsonPath('data.0.application.name', $app->name)->assertJsonCount(1, 'data.0.capabilities');
            $this->postJson("/api/v1/{$path}/{$owner->id}/capabilities/{$excluded->id}")->assertUnprocessable()->assertJsonPath('error.code', 'CAPABILITY_NOT_IN_PRODUCT');
            $this->postJson("/api/v1/{$path}/{$owner->id}/capabilities/{$chosen->id}")->assertCreated();
        }
    }

    public function test_names_are_returned_with_catalog_records(): void
    {
        $this->actingAsSuperAdmin();
        $product = $this->makeProduct(['name' => 'Fleet Suite']);
        $this->makePlan($product);
        $this->makeAddon($product);
        foreach (['plans', 'addons'] as $path) {
            $this->getJson("/api/v1/{$path}")->assertOk()->assertJsonPath('data.0.product_name', 'Fleet Suite');
        }
    }

    public function test_active_choices_are_available_with_consuming_action_permissions_only(): void
    {
        $this->actingAsOperatorWith(['cgo.subscription.create']);
        $product = $this->makeProduct();
        $active = $this->makePlan($product, ['status' => 'ACTIVE']);
        $this->makePlan($product, ['status' => 'DRAFT']);
        $this->getJson('/api/v1/form-options/plans')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id)->assertJsonMissingPath('data.0.metadata');
        $this->getJson('/api/v1/form-options/addons')->assertForbidden();
    }
}
