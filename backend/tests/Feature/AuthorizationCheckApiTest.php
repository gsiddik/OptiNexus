<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class AuthorizationCheckApiTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    private function issueServiceAccountToken(?string $applicationId = null, array $scopes = ['authorization.check', 'audit.write']): string
    {
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient('test-integration');

        ServiceAccount::create([
            'application_id' => $applicationId,
            'oauth_client_id' => $client->id,
            'name' => 'test-integration',
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

    public function test_authorized_permission_check_allows(): void
    {
        $application = $this->makeApplication(['application_code' => 'optifleet']);
        $tenant = $this->makeTenant();
        $this->grantApplicationAccess(($user = User::factory()->create(['status' => User::STATUS_ACTIVE])), $tenant, $application);

        $role = \App\Models\Role::create([
            'tenant_id' => $tenant->id, 'application_id' => $application->id, 'name' => 'App Admin', 'code' => 'APPADM1',
            'role_type' => \App\Models\Role::TYPE_APPLICATION, 'status' => \App\Models\Role::STATUS_ACTIVE,
        ]);
        $permission = $this->makePermission($application, 'optifleet.vehicle.update');
        $role->permissions()->attach($permission->id, ['id' => (string) \Illuminate\Support\Str::uuid()]);
        $this->attachUserToTenant($user, $tenant, $role);

        $token = $this->issueServiceAccountToken($application->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/authorization/check', [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'application_code' => 'optifleet',
                'permission' => 'optifleet.vehicle.update',
            ])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['allowed' => true, 'scope' => 'APPLICATION']]);
    }

    public function test_cross_tenant_check_is_denied(): void
    {
        $application = $this->makeApplication(['application_code' => 'optifleet']);
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->grantApplicationAccess($user, $tenantA, $application);

        $role = \App\Models\Role::create([
            'tenant_id' => $tenantA->id, 'application_id' => $application->id, 'name' => 'App Admin', 'code' => 'APPADM2',
            'role_type' => \App\Models\Role::TYPE_APPLICATION, 'status' => \App\Models\Role::STATUS_ACTIVE,
        ]);
        $permission = $this->makePermission($application, 'optifleet.vehicle.update');
        $role->permissions()->attach($permission->id, ['id' => (string) \Illuminate\Support\Str::uuid()]);
        $this->attachUserToTenant($user, $tenantA, $role);

        $token = $this->issueServiceAccountToken($application->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/authorization/check', [
                'user_id' => $user->id,
                'tenant_id' => $tenantB->id,
                'application_code' => 'optifleet',
                'permission' => 'optifleet.vehicle.update',
            ])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['allowed' => false, 'scope' => null]]);
    }

    public function test_check_without_token_is_rejected(): void
    {
        $this->postJson('/api/v1/authorization/check', [
            'user_id' => (string) \Illuminate\Support\Str::uuid(),
            'application_code' => 'optifleet',
            'permission' => 'optifleet.vehicle.update',
        ])->assertStatus(401);
    }

    public function test_revoked_service_account_is_rejected(): void
    {
        $token = $this->issueServiceAccountToken();
        ServiceAccount::query()->where('name', 'test-integration')->update(['status' => ServiceAccount::STATUS_REVOKED]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/authorization/check', [
                'user_id' => (string) \Illuminate\Support\Str::uuid(),
                'application_code' => 'optifleet',
                'permission' => 'optifleet.vehicle.update',
            ])->assertStatus(403);
    }

    public function test_suspended_tenant_denies_even_with_valid_role(): void
    {
        $application = $this->makeApplication(['application_code' => 'optifleet']);
        $tenant = $this->makeTenant(overrides: ['status' => 'SUSPENDED']);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $token = $this->issueServiceAccountToken($application->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/authorization/check', [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'application_code' => 'optifleet',
                'permission' => 'optifleet.vehicle.update',
            ])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['allowed' => false]]);
    }
}
