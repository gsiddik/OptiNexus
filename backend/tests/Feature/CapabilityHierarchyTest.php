<?php

namespace Tests\Feature;

use App\Models\Capability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class CapabilityHierarchyTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    public function test_module_menu_feature_hierarchy_can_be_built(): void
    {
        $this->actingAsSuperAdmin();
        $application = $this->makeApplication();

        $module = $this->postJson("/api/v1/applications/{$application->id}/capabilities", [
            'type' => Capability::TYPE_MODULE, 'code' => 'fleet', 'name' => 'Fleet',
        ])->assertStatus(201)->json('data.id');

        $menu = $this->postJson("/api/v1/applications/{$application->id}/capabilities", [
            'type' => Capability::TYPE_MENU, 'code' => 'vehicles', 'name' => 'Vehicles', 'parent_id' => $module,
        ])->assertStatus(201)->json('data.id');

        $feature = $this->postJson("/api/v1/applications/{$application->id}/capabilities", [
            'type' => Capability::TYPE_FEATURE, 'code' => 'vehicle_list', 'name' => 'Vehicle List', 'parent_id' => $menu,
        ])->assertStatus(201)->json('data.id');

        $this->getJson("/api/v1/applications/{$application->id}/capabilities?tree=1")
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $module)
            ->assertJsonPath('data.0.children.0.id', $menu)
            ->assertJsonPath('data.0.children.0.children.0.id', $feature);
    }

    public function test_module_cannot_have_a_parent(): void
    {
        $this->actingAsSuperAdmin();
        $application = $this->makeApplication();
        $module = $this->postJson("/api/v1/applications/{$application->id}/capabilities", [
            'type' => Capability::TYPE_MODULE, 'code' => 'm1', 'name' => 'M1',
        ])->json('data.id');

        $this->postJson("/api/v1/applications/{$application->id}/capabilities", [
            'type' => Capability::TYPE_MODULE, 'code' => 'm2', 'name' => 'M2', 'parent_id' => $module,
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_invalid_parent_child_type_is_rejected(): void
    {
        $this->actingAsSuperAdmin();
        $application = $this->makeApplication();
        $module = $this->postJson("/api/v1/applications/{$application->id}/capabilities", [
            'type' => Capability::TYPE_MODULE, 'code' => 'm1', 'name' => 'M1',
        ])->json('data.id');

        // An ACTION cannot be a direct child of a MODULE.
        $this->postJson("/api/v1/applications/{$application->id}/capabilities", [
            'type' => Capability::TYPE_ACTION, 'code' => 'a1', 'name' => 'A1', 'parent_id' => $module,
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_cross_application_parent_is_rejected(): void
    {
        $this->actingAsSuperAdmin();
        $appA = $this->makeApplication();
        $appB = $this->makeApplication();

        $moduleA = $this->postJson("/api/v1/applications/{$appA->id}/capabilities", [
            'type' => Capability::TYPE_MODULE, 'code' => 'm1', 'name' => 'M1',
        ])->json('data.id');

        $this->postJson("/api/v1/applications/{$appB->id}/capabilities", [
            'type' => Capability::TYPE_MENU, 'code' => 'menu1', 'name' => 'Menu1', 'parent_id' => $moduleA,
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_circular_hierarchy_is_rejected_on_move(): void
    {
        $this->actingAsSuperAdmin();
        $application = $this->makeApplication();

        $module = $this->postJson("/api/v1/applications/{$application->id}/capabilities", [
            'type' => Capability::TYPE_MODULE, 'code' => 'm1', 'name' => 'M1',
        ])->json('data.id');

        $menu = $this->postJson("/api/v1/applications/{$application->id}/capabilities", [
            'type' => Capability::TYPE_MENU, 'code' => 'menu1', 'name' => 'Menu1', 'parent_id' => $module,
        ])->json('data.id');

        // Moving the module under its own descendant menu must be rejected.
        $this->postJson("/api/v1/capabilities/{$module}/move", ['parent_id' => $menu])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_duplicate_code_within_application_is_rejected(): void
    {
        $this->actingAsSuperAdmin();
        $application = $this->makeApplication();

        $this->postJson("/api/v1/applications/{$application->id}/capabilities", [
            'type' => Capability::TYPE_MODULE, 'code' => 'dup', 'name' => 'Dup',
        ])->assertStatus(201);

        $this->postJson("/api/v1/applications/{$application->id}/capabilities", [
            'type' => Capability::TYPE_MODULE, 'code' => 'dup', 'name' => 'Dup Again',
        ])->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_RESOURCE');
    }

    public function test_capability_lifecycle_status_transitions(): void
    {
        $this->actingAsSuperAdmin();
        $application = $this->makeApplication();
        $id = $this->postJson("/api/v1/applications/{$application->id}/capabilities", [
            'type' => Capability::TYPE_MODULE, 'code' => 'm1', 'name' => 'M1',
        ])->json('data.id');

        $this->postJson("/api/v1/capabilities/{$id}/disable")->assertJsonPath('data.status', 'DISABLED');
        $this->postJson("/api/v1/capabilities/{$id}/activate")->assertJsonPath('data.status', 'ACTIVE');
        $this->postJson("/api/v1/capabilities/{$id}/deprecate")->assertJsonPath('data.status', 'DEPRECATED');
    }
}
