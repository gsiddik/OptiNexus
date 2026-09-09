<?php

namespace Tests\Concerns;

use App\Models\Application;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\OrchestrationPermissionSeeder;
use Database\Seeders\OrchestrationRoleSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

trait CreatesOrchestrationFixtures
{
    private bool $orchestrationBaselineSeeded = false;

    protected function seedOrchestrationBaseline(): void
    {
        if ($this->orchestrationBaselineSeeded) {
            return;
        }

        $this->seedGovernanceBaseline();
        $this->seed(OrchestrationPermissionSeeder::class);
        $this->seed(OrchestrationRoleSeeder::class);

        $this->orchestrationBaselineSeeded = true;
    }

    protected function actingAsOrchestrationRole(string $roleCode, ?string $tenantId = null): User
    {
        $this->seedOrchestrationBaseline();

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $role = Role::query()->where('code', $roleCode)->whereNull('tenant_id')->firstOrFail();
        $user->userRoles()->create(['role_id' => $role->id, 'tenant_id' => $tenantId]);

        Sanctum::actingAs($user);

        return $user;
    }

    protected function cgoApplication(): Application
    {
        return Application::query()->where('application_code', 'cgo')->first()
            ?? Application::create(['application_code' => 'cgo', 'name' => 'CGO Platform', 'status' => Application::STATUS_PUBLISHED]);
    }
}
