<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class TenantLifecycleTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    public function test_full_lifecycle_from_draft_to_archived(): void
    {
        $this->actingAsSuperAdmin();
        $customer = $this->makeCustomer();

        $create = $this->postJson('/api/v1/tenants', [
            'tenant_code' => 'T-LIFECYCLE',
            'customer_id' => $customer->id,
            'name' => 'Lifecycle Tenant',
        ])->assertStatus(201)->assertJsonPath('data.status', Tenant::STATUS_DRAFT);

        $id = $create->json('data.id');

        $this->postJson("/api/v1/tenants/{$id}/provision")->assertJsonPath('data.status', Tenant::STATUS_PROVISIONING);
        $this->postJson("/api/v1/tenants/{$id}/activate")->assertJsonPath('data.status', Tenant::STATUS_ACTIVE);
        $this->postJson("/api/v1/tenants/{$id}/suspend")->assertJsonPath('data.status', Tenant::STATUS_SUSPENDED);
        $this->postJson("/api/v1/tenants/{$id}/reactivate")->assertJsonPath('data.status', Tenant::STATUS_ACTIVE);
        $this->postJson("/api/v1/tenants/{$id}/terminate")->assertJsonPath('data.status', Tenant::STATUS_TERMINATED);
        $this->postJson("/api/v1/tenants/{$id}/archive")->assertJsonPath('data.status', Tenant::STATUS_ARCHIVED);

        // Skipping provisioning entirely is not a valid path.
        $create2 = $this->postJson('/api/v1/tenants', [
            'tenant_code' => 'T-SKIP',
            'customer_id' => $customer->id,
            'name' => 'Skip Tenant',
        ])->json('data.id');

        $this->postJson("/api/v1/tenants/{$create2}/activate")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
    }

    public function test_application_assignment_and_removal(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $application = $this->makeApplication();

        $this->postJson("/api/v1/tenants/{$tenant->id}/applications/{$application->id}")
            ->assertStatus(201);

        $this->getJson("/api/v1/tenants/{$tenant->id}/applications")
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $application->id]);

        $this->postJson("/api/v1/tenants/{$tenant->id}/applications/{$application->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_RESOURCE');

        $this->deleteJson("/api/v1/tenants/{$tenant->id}/applications/{$application->id}")
            ->assertStatus(200);

        $this->getJson("/api/v1/tenants/{$tenant->id}/applications")
            ->assertJsonMissing(['id' => $application->id]);
    }

    public function test_tenant_admin_assignment(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $user = \App\Models\User::factory()->create(['status' => \App\Models\User::STATUS_ACTIVE]);

        $this->postJson("/api/v1/tenants/{$tenant->id}/admins/{$user->id}")->assertStatus(201);

        $this->getJson("/api/v1/tenants/{$tenant->id}/admins")
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $user->id]);

        $this->deleteJson("/api/v1/tenants/{$tenant->id}/admins/{$user->id}")->assertStatus(200);

        $this->getJson("/api/v1/tenants/{$tenant->id}/admins")
            ->assertJsonMissing(['id' => $user->id]);
    }
}
