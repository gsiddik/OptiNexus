<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Capability;
use App\Models\Entitlement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\Commercial\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\IssuesServiceAccountTokens;
use Tests\TestCase;

class EffectivePermissionsApiTest extends TestCase
{
    use CreatesGovernanceFixtures, IssuesServiceAccountTokens, RefreshDatabase;

    private const ENDPOINT = '/api/v1/authorization/effective-permissions';

    private Application $app1;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app1 = $this->makeApplication(['application_code' => 'entry-test']);
        $this->tenant = $this->makeTenant();
        $this->user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->grantApplicationAccess($this->user, $this->tenant, $this->app1);
    }

    /** @return list<Permission> */
    private function catalog(Application $application, int $count, string $prefix = 'entry-test'): array
    {
        return array_map(fn (int $i) => $this->makePermission($application, sprintf('%s.res%03d.act', $prefix, $i)), range(1, $count));
    }

    private function applicationRole(Tenant $tenant, Application $application, array $permissions, string $code): Role
    {
        $role = Role::create([
            'tenant_id' => $tenant->id, 'application_id' => $application->id, 'name' => $code, 'code' => $code,
            'role_type' => Role::TYPE_APPLICATION, 'status' => Role::STATUS_ACTIVE,
        ]);
        foreach ($permissions as $permission) {
            $role->permissions()->attach($permission->id, ['id' => (string) Str::uuid()]);
        }

        return $role;
    }

    private function ask(string $token, array $body): TestResponse
    {
        return $this->withHeader('Authorization', "Bearer {$token}")->postJson(self::ENDPOINT, $body);
    }

    private function body(?User $user = null, ?Tenant $tenant = null): array
    {
        return ['user_id' => ($user ?? $this->user)->id, 'tenant_id' => ($tenant ?? $this->tenant)->id];
    }

    public function test_answers_a_catalog_larger_than_the_old_rate_limit_in_one_request_and_matches_the_individual_check(): void
    {
        $permissions = $this->catalog($this->app1, 150);
        $this->attachUserToTenant($this->user, $this->tenant, $this->applicationRole($this->tenant, $this->app1, array_slice($permissions, 0, 140), 'ENTRYADM'));
        [$token] = $this->issueServiceAccountTokenFor($this->app1->id);

        $response = $this->ask($token, $this->body())->assertOk()
            ->assertJsonPath('data.schema_version', '1.0')
            ->assertJsonPath('data.application_code', 'entry-test')
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.deny_reason', null)
            ->assertJsonPath('data.ttl_seconds', 300);

        $granted = collect($response->json('data.permissions'))->pluck('scope', 'permission');
        $this->assertCount(140, $granted);

        // Same answer as asking one key at a time, for every key of the catalog (granted or not).
        $authorization = app(AuthorizationService::class);
        foreach ($permissions as $permission) {
            $single = $authorization->check($this->user, $this->tenant, $this->app1, $permission->permission_key);
            $this->assertSame($single['allowed'], $granted->has($permission->permission_key), $permission->permission_key);
            $this->assertSame($single['scope'], $granted->get($permission->permission_key), $permission->permission_key);
        }
    }

    public function test_each_permission_carries_the_scope_of_the_role_that_grants_it(): void
    {
        [$tenantPerm, $appPerm] = $this->catalog($this->app1, 2);
        $tenantRole = $this->makeTenantRole($this->tenant, [$tenantPerm->permission_key]);
        $this->attachUserToTenant($this->user, $this->tenant, $tenantRole);
        $this->user->userRoles()->create(['role_id' => $this->applicationRole($this->tenant, $this->app1, [$appPerm], 'APPR')->id, 'tenant_id' => $this->tenant->id]);
        [$token] = $this->issueServiceAccountTokenFor($this->app1->id);

        $scopes = collect($this->ask($token, $this->body())->assertOk()->json('data.permissions'))->pluck('scope', 'permission');

        $this->assertSame('TENANT', $scopes[$tenantPerm->permission_key]);
        $this->assertSame('APPLICATION', $scopes[$appPerm->permission_key]);
    }

    public function test_the_application_comes_from_the_service_account_not_from_the_body(): void
    {
        $other = $this->makeApplication(['application_code' => 'other-app']);
        $this->catalog($this->app1, 1);
        $this->catalog($other, 1, 'other-app');
        [$token] = $this->issueServiceAccountTokenFor($this->app1->id);

        $this->ask($token, $this->body() + ['application_code' => 'other-app'])
            ->assertForbidden()->assertJsonPath('error.code', 'APPLICATION_MISMATCH');

        $this->ask($token, $this->body() + ['application_code' => 'entry-test'])->assertOk();
    }

    public function test_a_service_account_bound_to_no_application_is_refused(): void
    {
        [$token] = $this->issueServiceAccountTokenFor(null);

        $this->ask($token, $this->body())->assertForbidden()->assertJsonPath('error.code', 'SERVICE_ACCOUNT_NOT_BOUND');
    }

    public function test_a_service_account_bound_to_one_tenant_cannot_ask_about_another(): void
    {
        $otherTenant = $this->makeTenant();
        [$token] = $this->issueServiceAccountTokenFor($this->app1->id, tenantId: $this->tenant->id);

        $this->ask($token, $this->body(tenant: $otherTenant))->assertForbidden()->assertJsonPath('error.code', 'TENANT_MISMATCH');
        $this->ask($token, $this->body())->assertOk();
    }

    public function test_grants_of_another_tenant_or_application_are_not_returned(): void
    {
        $other = $this->makeApplication(['application_code' => 'other-app']);
        $otherTenant = $this->makeTenant();
        [$mine] = $this->catalog($this->app1, 1);
        [$theirs] = $this->catalog($other, 1, 'other-app');
        $this->grantApplicationAccess($this->user, $this->tenant, $other);
        $this->attachUserToTenant($this->user, $this->tenant, $this->applicationRole($this->tenant, $other, [$theirs], 'OTHERADM'));
        $this->grantApplicationAccess($this->user, $otherTenant, $this->app1);
        TenantMembership::create(['user_id' => $this->user->id, 'tenant_id' => $otherTenant->id, 'status' => TenantMembership::STATUS_ACTIVE]);
        $this->user->userRoles()->create(['role_id' => $this->applicationRole($otherTenant, $this->app1, [$mine], 'MINEADM')->id, 'tenant_id' => $otherTenant->id]);
        [$token] = $this->issueServiceAccountTokenFor($this->app1->id);

        // In this tenant the user holds only the other application's role; in the other tenant only this application's.
        $this->ask($token, $this->body())->assertOk()
            ->assertJsonPath('data.allowed', false)->assertJsonPath('data.deny_reason', 'PERMISSION_NOT_GRANTED')->assertJsonPath('data.permissions', []);
        $this->ask($token, $this->body(tenant: $otherTenant))->assertOk()
            ->assertJsonPath('data.permissions.0.permission', $mine->permission_key);
    }

    public function test_revoking_the_role_or_the_membership_takes_effect_on_the_next_request(): void
    {
        [$permission] = $this->catalog($this->app1, 1);
        $role = $this->applicationRole($this->tenant, $this->app1, [$permission], 'ENTRYADM');
        $this->attachUserToTenant($this->user, $this->tenant, $role);
        [$token] = $this->issueServiceAccountTokenFor($this->app1->id);

        $this->ask($token, $this->body())->assertJsonPath('data.allowed', true);

        $this->user->userRoles()->where('role_id', $role->id)->delete();
        $this->ask($token, $this->body())->assertJsonPath('data.allowed', false)->assertJsonPath('data.permissions', []);

        $this->user->userRoles()->create(['role_id' => $role->id, 'tenant_id' => $this->tenant->id]);
        $this->ask($token, $this->body())->assertJsonPath('data.allowed', true);

        TenantMembership::query()->where('user_id', $this->user->id)->update(['status' => TenantMembership::STATUS_SUSPENDED]);
        $this->ask($token, $this->body())->assertJsonPath('data.allowed', false)->assertJsonPath('data.permissions', []);
    }

    #[DataProvider('unusableSubjects')]
    public function test_an_unusable_user_tenant_or_application_gets_no_permissions_and_a_reason(string $what, string $reason): void
    {
        [$permission] = $this->catalog($this->app1, 1);
        $this->attachUserToTenant($this->user, $this->tenant, $this->applicationRole($this->tenant, $this->app1, [$permission], 'ENTRYADM'));
        [$token] = $this->issueServiceAccountTokenFor($this->app1->id);

        match ($what) {
            'user' => $this->user->update(['status' => User::STATUS_SUSPENDED]),
            'tenant' => $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]),
            'application' => $this->app1->update(['status' => Application::STATUS_DRAFT]),
        };

        $this->ask($token, $this->body())->assertOk()
            ->assertJsonPath('data.allowed', false)->assertJsonPath('data.deny_reason', $reason)->assertJsonPath('data.permissions', []);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function unusableSubjects(): array
    {
        return [
            'inactive user' => ['user', 'USER_NOT_ACTIVE'],
            'suspended tenant' => ['tenant', 'TENANT_NOT_ACTIVE'],
            'unpublished application' => ['application', 'APPLICATION_NOT_ACTIVE'],
        ];
    }

    public function test_unknown_user_and_unknown_tenant_get_no_permissions(): void
    {
        [$token] = $this->issueServiceAccountTokenFor($this->app1->id);

        $this->ask($token, ['user_id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id])->assertOk()
            ->assertJsonPath('data.deny_reason', 'USER_NOT_FOUND')->assertJsonPath('data.permissions', []);
        $this->ask($token, ['user_id' => $this->user->id, 'tenant_id' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('data.deny_reason', 'TENANT_NOT_FOUND')->assertJsonPath('data.permissions', []);
    }

    public function test_a_denied_subject_is_audited_like_an_individual_denial(): void
    {
        [$token] = $this->issueServiceAccountTokenFor($this->app1->id);
        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->ask($token, $this->body())->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'authorization.denied', 'actor_user_id' => $this->user->id, 'application_id' => $this->app1->id]);
    }

    public function test_authentication_scope_and_validation_are_enforced(): void
    {
        $this->postJson(self::ENDPOINT, $this->body())->assertUnauthorized();

        [$wrongScope] = $this->issueServiceAccountTokenFor($this->app1->id, ['audit.write']);
        $this->ask($wrongScope, $this->body())->assertForbidden();

        [$token] = $this->issueServiceAccountTokenFor($this->app1->id, name: 'second');
        $this->ask($token, [])->assertUnprocessable()->assertJsonValidationErrorFor('user_id', 'error.details')->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->ask($token, ['user_id' => 'not-a-uuid', 'tenant_id' => $this->tenant->id])->assertUnprocessable();
        $this->ask($token, ['user_id' => $this->user->id])->assertUnprocessable();
    }

    public function test_the_individual_endpoint_keeps_working_beside_the_bulk_one(): void
    {
        [$permission] = $this->catalog($this->app1, 1);
        $this->attachUserToTenant($this->user, $this->tenant, $this->applicationRole($this->tenant, $this->app1, [$permission], 'ENTRYADM'));
        [$token] = $this->issueServiceAccountTokenFor($this->app1->id);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/authorization/check', $this->body() + [
            'application_code' => 'entry-test', 'permission' => $permission->permission_key,
        ])->assertOk()->assertJsonPath('data.allowed', true)->assertJsonPath('data.scope', 'APPLICATION');
    }

    public function test_lists_the_modules_and_features_the_tenant_is_entitled_to_for_the_callers_application_only(): void
    {
        $permissions = $this->catalog($this->app1, 2);
        $this->attachUserToTenant($this->user, $this->tenant, $this->applicationRole($this->tenant, $this->app1, $permissions, 'ENTRYADM'));
        $other = $this->makeApplication(['application_code' => 'other-test']);
        foreach ([[$this->app1, 'BUDGET', Capability::TYPE_MODULE], [$this->app1, 'ASSETS', Capability::TYPE_MODULE], [$other, 'FOREIGN', Capability::TYPE_MODULE]] as [$application, $code, $type]) {
            Capability::create(['application_id' => $application->id, 'type' => $type, 'code' => $code, 'name' => $code, 'status' => Capability::STATUS_ACTIVE]);
        }
        foreach ([[$this->app1, 'BUDGET'], [$other, 'FOREIGN']] as [$application, $code]) {
            app(EntitlementService::class)->manualOverride($this->tenant, ['entitlement_type' => 'CAPABILITY', 'entitlement_key' => $code, 'value' => 'true', 'application_id' => $application->id], null);
        }
        [$token] = $this->issueServiceAccountTokenFor($this->app1->id);

        $this->ask($token, $this->body())->assertOk()
            ->assertJsonPath('data.entitled_capabilities', [['code' => 'BUDGET', 'type' => Capability::TYPE_MODULE]]);

        Entitlement::query()->where('entitlement_key', 'BUDGET')->update(['status' => 'REVOKED']);

        $this->ask($token, $this->body())->assertOk()->assertJsonPath('data.entitled_capabilities', []);
    }

    public function test_no_capabilities_are_listed_when_access_is_denied(): void
    {
        [$token] = $this->issueServiceAccountTokenFor($this->app1->id);
        $stranger = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->ask($token, $this->body($stranger))->assertOk()
            ->assertJsonPath('data.allowed', false)
            ->assertJsonPath('data.entitled_capabilities', []);
    }
}
