<?php

namespace Tests\Feature\Governance;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Entitlement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserRole;
use App\Services\AuthorizationService;
use App\Services\Commercial\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\IssuesServiceAccountTokens;
use Tests\TestCase;

/**
 * Audit R08/A25: an Admin Tenant manages the users and custom roles of their own tenant from a product, through the
 * delegation API, inside an explicit delegable permission set - and nothing else.
 */
class TenantDelegationApiTest extends TestCase
{
    use CreatesGovernanceFixtures, IssuesServiceAccountTokens, RefreshDatabase;

    private Application $entry;

    private Application $otherApp;

    private Tenant $tenant;

    private User $admin;

    private string $token;

    private ServiceAccount $account;

    /** @var array<string, Permission> */
    private array $perm = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedGovernanceBaseline();
        $this->entry = $this->makeApplication(['application_code' => 'entry-test']);
        $this->otherApp = $this->makeApplication(['application_code' => 'other-test']);
        $this->tenant = $this->makeTenant();
        $this->tenant->applications()->attach($this->entry->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);

        $this->perm['journal.view'] = $this->makePermission($this->entry, 'entry-test.journal.view');
        $this->perm['journal.post'] = $this->makePermission($this->entry, 'entry-test.journal.post');
        $this->perm['period.close'] = $this->makePermission($this->entry, 'entry-test.period.close');
        $this->perm['budget.view'] = $this->makePermission($this->entry, 'entry-test.budget.view', $this->capability('BUDGET'));
        $this->perm['other.view'] = $this->makePermission($this->otherApp, 'other-test.thing.view');
        foreach (['journal.view', 'journal.post', 'budget.view', 'other.view'] as $key) {
            $this->perm[$key]->update(['delegable' => true]);
        }

        $this->admin = $this->member(admin: true, name: 'Tenant Admin');
        [$this->token, $this->account] = $this->issueServiceAccountTokenFor($this->entry->id, ['tenant.delegate']);
    }

    private function capability(string $code): Capability
    {
        return Capability::create(['application_id' => $this->entry->id, 'type' => Capability::TYPE_MODULE, 'code' => $code, 'name' => $code, 'status' => Capability::STATUS_ACTIVE]);
    }

    private function member(bool $admin = false, string $name = 'Member', ?Tenant $tenant = null, bool $access = true, string $status = User::STATUS_ACTIVE): User
    {
        $tenant ??= $this->tenant;
        $user = User::factory()->create(['name' => $name, 'status' => $status]);
        TenantMembership::create(['user_id' => $user->id, 'tenant_id' => $tenant->id, 'status' => TenantMembership::STATUS_ACTIVE, 'is_tenant_admin' => $admin]);
        if ($access) {
            UserApplicationAccess::create(['user_id' => $user->id, 'tenant_id' => $tenant->id, 'application_id' => $this->entry->id, 'status' => UserApplicationAccess::STATUS_ACTIVE]);
        }

        return $user;
    }

    private function delegate(string $method, string $path, array $body = [], ?User $actor = null, ?string $token = null, ?Tenant $tenant = null): TestResponse
    {
        $tenant ??= $this->tenant;
        $payload = $body + ['acting_user_id' => ($actor ?? $this->admin)->id];
        $url = "/api/v1/delegation/tenants/{$tenant->id}{$path}";
        $client = $this->withHeader('Authorization', 'Bearer '.($token ?? $this->token));

        return in_array($method, ['GET', 'DELETE'], true)
            ? $client->json($method, $url.'?'.http_build_query($payload))
            : $client->json($method, $url, $payload);
    }

    private function createRole(array $permissions = ['entry-test.journal.view'], string $name = 'Clerk'): TestResponse
    {
        return $this->delegate('POST', '/roles', ['name' => $name, 'permissions' => $permissions]);
    }

    // ------------------------------------------------------------ catalog / delegable set

    public function test_the_catalog_lists_only_the_explicit_delegable_set_of_the_callers_application(): void
    {
        $keys = collect($this->delegate('GET', '/catalog')->assertOk()->json('data.delegable_permissions'))->pluck('permission')->all();

        // post/view: delegable. period.close: not flagged. budget.view: its module is not in the tenant entitlements. other.view: other app.
        $this->assertSame(['entry-test.journal.post', 'entry-test.journal.view'], $keys);
    }

    public function test_an_entitled_module_makes_its_permissions_delegable_and_losing_it_removes_them_again(): void
    {
        app(EntitlementService::class)->manualOverride($this->tenant, [
            'entitlement_type' => 'CAPABILITY', 'entitlement_key' => 'BUDGET', 'value' => 'true', 'application_id' => $this->entry->id,
        ], null);

        $keys = collect($this->delegate('GET', '/catalog')->json('data.delegable_permissions'))->pluck('permission');
        $this->assertTrue($keys->contains('entry-test.budget.view'));

        $roleId = $this->createRole(['entry-test.budget.view'])->assertCreated()->json('data.id');
        $user = $this->member(name: 'Budget clerk');
        $this->delegate('PUT', "/users/{$user->id}/roles", ['role_ids' => [$roleId]])->assertOk();

        Entitlement::query()->where('entitlement_key', 'BUDGET')->update(['status' => 'REVOKED']);

        $this->assertFalse(collect($this->delegate('GET', '/catalog')->json('data.delegable_permissions'))->pluck('permission')->contains('entry-test.budget.view'));
        // A role that now carries a non-delegable permission can no longer be handed out.
        $another = $this->member(name: 'Another');
        $this->delegate('PUT', "/users/{$another->id}/roles", ['role_ids' => [$roleId]])->assertStatus(422)->assertJsonPath('error.code', 'ROLE_NOT_ASSIGNABLE');
    }

    // ------------------------------------------------------------ who may call

    public function test_a_plain_member_cannot_act_and_learns_nothing_about_why(): void
    {
        $member = $this->member(name: 'Plain');

        foreach ([$member, User::factory()->create(), $this->member(admin: true, access: false, name: 'No access')] as $actor) {
            $this->delegate('GET', '/users', [], $actor)->assertStatus(403)->assertJsonPath('error.code', 'ACTOR_NOT_TENANT_ADMIN');
        }

        $suspended = $this->member(admin: true, name: 'Suspended', status: User::STATUS_SUSPENDED);
        $this->delegate('GET', '/users', [], $suspended)->assertStatus(403)->assertJsonPath('error.code', 'ACTOR_NOT_TENANT_ADMIN');
    }

    public function test_an_admin_of_another_tenant_cannot_act_here(): void
    {
        $otherTenant = $this->makeTenant();
        $otherTenant->applications()->attach($this->entry->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $foreignAdmin = $this->member(admin: true, name: 'Foreign admin', tenant: $otherTenant);

        $this->delegate('GET', '/users', [], $foreignAdmin)->assertStatus(403)->assertJsonPath('error.code', 'ACTOR_NOT_TENANT_ADMIN');
        $this->delegate('POST', '/roles', ['name' => 'X', 'permissions' => ['entry-test.journal.view']], $foreignAdmin)->assertStatus(403);
        $this->assertSame(0, Role::query()->where('origin', Role::ORIGIN_DELEGATION)->count());
    }

    public function test_the_service_account_tenant_application_and_scope_are_enforced(): void
    {
        [$unbound] = $this->issueServiceAccountTokenFor(null, ['tenant.delegate']);
        $this->delegate('GET', '/users', [], null, $unbound)->assertStatus(403)->assertJsonPath('error.code', 'SERVICE_ACCOUNT_NOT_BOUND');

        [$pinned] = $this->issueServiceAccountTokenFor($this->entry->id, ['tenant.delegate'], $this->makeTenant()->id);
        $this->delegate('GET', '/users', [], null, $pinned)->assertStatus(403)->assertJsonPath('error.code', 'TENANT_MISMATCH');

        $cgo = Application::query()->where('application_code', 'cgo')->firstOrFail();
        [$governance] = $this->issueServiceAccountTokenFor($cgo->id, ['tenant.delegate']);
        $this->delegate('GET', '/users', [], null, $governance)->assertStatus(403)->assertJsonPath('error.code', 'APPLICATION_NOT_DELEGABLE');

        [$wrongScope] = $this->issueServiceAccountTokenFor($this->entry->id, ['authorization.check']);
        $this->delegate('GET', '/users', [], null, $wrongScope)->assertStatus(403);

        $this->flushHeaders();
        $this->getJson("/api/v1/delegation/tenants/{$this->tenant->id}/users?acting_user_id={$this->admin->id}")->assertStatus(401);
        $this->delegate('GET', '/users', [], null, null, $this->tenant)->assertOk();
    }

    public function test_an_unsubscribed_application_or_an_inactive_tenant_is_refused(): void
    {
        $this->tenant->applications()->updateExistingPivot($this->entry->id, ['status' => 'SUSPENDED']);
        $this->delegate('GET', '/users')->assertStatus(403)->assertJsonPath('error.code', 'APPLICATION_NOT_SUBSCRIBED');
        $this->createRole()->assertStatus(403);

        $this->tenant->applications()->updateExistingPivot($this->entry->id, ['status' => 'ACTIVE']);
        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
        $this->delegate('GET', '/users')->assertStatus(403)->assertJsonPath('error.code', 'TENANT_NOT_ACTIVE');
    }

    public function test_an_unknown_tenant_is_not_found(): void
    {
        $ghost = new Tenant;
        $ghost->id = (string) Str::uuid();
        $this->delegate('GET', '/users', [], null, null, $ghost)->assertStatus(404);
    }

    // ------------------------------------------------------------ roles

    public function test_an_admin_creates_a_custom_role_inside_the_delegable_set(): void
    {
        $response = $this->createRole(['entry-test.journal.view', 'entry-test.journal.post'], 'Clerk')->assertCreated();

        $role = Role::query()->findOrFail($response->json('data.id'));
        $this->assertSame(Role::TYPE_APPLICATION, $role->role_type);
        $this->assertSame($this->tenant->id, $role->tenant_id);
        $this->assertSame($this->entry->id, $role->application_id);
        $this->assertSame(Role::ORIGIN_DELEGATION, $role->origin);
        $this->assertSame($this->admin->id, $role->created_by);
        $this->assertEqualsCanonicalizing(['entry-test.journal.post', 'entry-test.journal.view'], $role->permissions()->pluck('permission_key')->all());
        $this->assertTrue(AuditLog::query()->where('action', 'delegation.role_created')->where('actor_user_id', $this->admin->id)->exists());
    }

    public function test_a_role_cannot_carry_what_is_not_delegable(): void
    {
        foreach ([
            'ecosystem permission' => ['cgo.tenant.update'],
            'ecosystem access' => ['cgo.ecosystem.access'],
            'not flagged delegable' => ['entry-test.period.close'],
            'another application' => ['other-test.thing.view'],
            'module not entitled' => ['entry-test.budget.view'],
            'unknown key' => ['entry-test.nothing'],
            'one good, one bad' => ['entry-test.journal.view', 'entry-test.period.close'],
        ] as $label => $permissions) {
            $this->createRole($permissions)->assertStatus(422)->assertJsonPath('error.code', 'PERMISSION_NOT_DELEGABLE');
            $this->assertSame(0, Role::query()->where('origin', Role::ORIGIN_DELEGATION)->count(), $label);
        }
    }

    public function test_the_delegable_set_is_not_the_admins_own_permissions(): void
    {
        // The Admin Tenant personally holds nothing at all, yet may delegate; and holding period.close personally does not make it delegable.
        $adminRole = Role::create(['tenant_id' => $this->tenant->id, 'application_id' => $this->entry->id, 'name' => 'Admin', 'code' => 'ADMIN', 'role_type' => Role::TYPE_APPLICATION, 'status' => Role::STATUS_ACTIVE]);
        $adminRole->permissions()->attach($this->perm['period.close']->id, ['id' => (string) Str::uuid()]);
        UserRole::create(['user_id' => $this->admin->id, 'role_id' => $adminRole->id, 'tenant_id' => $this->tenant->id]);

        $this->createRole(['entry-test.period.close'])->assertStatus(422)->assertJsonPath('error.code', 'PERMISSION_NOT_DELEGABLE');
        $this->createRole(['entry-test.journal.post'])->assertCreated();
    }

    public function test_only_delegation_roles_of_this_tenant_and_application_can_be_changed_or_deleted(): void
    {
        $mine = $this->createRole()->json('data.id');
        $operatorRole = $this->makeTenantRole($this->tenant, ['entry-test.journal.view'], ['role_type' => Role::TYPE_APPLICATION, 'application_id' => $this->entry->id]);

        $otherTenant = $this->makeTenant();
        $otherTenant->applications()->attach($this->entry->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $otherAdmin = $this->member(admin: true, tenant: $otherTenant, name: 'Other admin');

        $this->delegate('PUT', "/roles/{$mine}", ['name' => 'Renamed', 'permissions' => ['entry-test.journal.post']])->assertOk()->assertJsonPath('data.permissions.0', 'entry-test.journal.post');
        $this->delegate('PUT', "/roles/{$operatorRole->id}", ['name' => 'Hijack'])->assertStatus(404)->assertJsonPath('error.code', 'ROLE_NOT_EDITABLE');
        $this->delegate('DELETE', "/roles/{$operatorRole->id}")->assertStatus(404);
        // Another tenant's admin cannot touch this tenant's role, and vice versa.
        $this->delegate('PUT', "/roles/{$mine}", ['name' => 'Steal'], $otherAdmin, null, $otherTenant)->assertStatus(404);
        $this->delegate('PUT', "/roles/{$mine}", ['name' => 'Steal'], $otherAdmin)->assertStatus(403);
        $this->assertSame('Renamed', Role::query()->findOrFail($mine)->name);

        $this->delegate('DELETE', "/roles/{$mine}")->assertOk();
        $this->assertNull(Role::query()->find($mine));
    }

    public function test_a_role_in_use_cannot_be_deleted(): void
    {
        $roleId = $this->createRole()->json('data.id');
        $user = $this->member(name: 'Holder');
        $this->delegate('PUT', "/users/{$user->id}/roles", ['role_ids' => [$roleId]])->assertOk();

        $this->delegate('DELETE', "/roles/{$roleId}")->assertStatus(409)->assertJsonPath('error.code', 'ROLE_IN_USE');
    }

    // ------------------------------------------------------------ users

    public function test_creating_a_user_builds_the_central_state_and_is_idempotent(): void
    {
        $roleId = $this->createRole()->json('data.id');

        $first = $this->delegate('POST', '/users', ['email' => 'New.Person@Example.test', 'name' => 'New Person', 'role_ids' => [$roleId]])
            ->assertCreated()->assertJsonPath('data.outcome', 'created')->assertJsonPath('data.status', User::STATUS_INVITED);
        $userId = $first->json('data.id');

        $this->assertSame('new.person@example.test', User::query()->findOrFail($userId)->email);
        $this->assertDatabaseHas('tenant_memberships', ['user_id' => $userId, 'tenant_id' => $this->tenant->id, 'status' => 'ACTIVE', 'is_tenant_admin' => false]);
        $this->assertDatabaseHas('user_application_access', ['user_id' => $userId, 'tenant_id' => $this->tenant->id, 'application_id' => $this->entry->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('user_roles', ['user_id' => $userId, 'role_id' => $roleId, 'tenant_id' => $this->tenant->id]);

        $again = $this->delegate('POST', '/users', ['email' => 'NEW.person@example.test', 'name' => 'Different Name', 'role_ids' => [$roleId]])->assertOk();
        $again->assertJsonPath('data.outcome', 'existing')->assertJsonPath('data.id', $userId);

        $this->assertSame(1, User::query()->where('email', 'new.person@example.test')->count());
        $this->assertSame(1, TenantMembership::query()->where('user_id', $userId)->count());
        $this->assertSame(1, UserApplicationAccess::query()->where('user_id', $userId)->count());
        $this->assertSame(1, UserRole::query()->where('user_id', $userId)->count());
        $this->assertSame('New Person', User::query()->findOrFail($userId)->name);

        // Once the identity can sign in, the person holds exactly the delegated permissions in Nexus.
        User::query()->whereKey($userId)->update(['status' => User::STATUS_ACTIVE]);
        $access = app(AuthorizationService::class)->effectiveAccess(User::query()->findOrFail($userId), $this->tenant, $this->entry);
        $this->assertSame(['entry-test.journal.view' => 'APPLICATION'], $access['permissions']);
    }

    public function test_an_initial_password_makes_a_new_identity_active_and_never_changes_an_existing_one(): void
    {
        $this->delegate('POST', '/users', ['email' => 'fresh@example.test', 'name' => 'Fresh', 'initial_password' => 'a-long-initial-secret'])
            ->assertCreated()->assertJsonPath('data.status', User::STATUS_ACTIVE);

        $existing = User::factory()->create(['email' => 'veteran@example.test', 'status' => User::STATUS_ACTIVE]);
        $hash = $existing->password;
        $this->delegate('POST', '/users', ['email' => 'veteran@example.test', 'name' => 'Veteran', 'initial_password' => 'another-long-secret'])
            ->assertOk()->assertJsonPath('data.outcome', 'linked');
        $this->assertSame($hash, $existing->refresh()->password);

        $this->delegate('POST', '/users', ['email' => 'short@example.test', 'name' => 'S', 'initial_password' => 'short'])->assertStatus(422);
    }

    public function test_an_identity_of_another_tenant_is_linked_without_touching_that_tenant(): void
    {
        $otherTenant = $this->makeTenant();
        $traveller = $this->member(tenant: $otherTenant, name: 'Traveller');
        $beforeOther = TenantMembership::query()->where('user_id', $traveller->id)->where('tenant_id', $otherTenant->id)->count();

        $this->delegate('POST', '/users', ['email' => $traveller->email, 'name' => 'Traveller'])->assertOk()->assertJsonPath('data.outcome', 'linked');

        $this->assertSame(1, User::query()->where('email', $traveller->email)->count());
        $this->assertDatabaseHas('tenant_memberships', ['user_id' => $traveller->id, 'tenant_id' => $this->tenant->id]);
        $this->assertSame($beforeOther, TenantMembership::query()->where('user_id', $traveller->id)->where('tenant_id', $otherTenant->id)->count());
    }

    public function test_identities_that_must_not_be_linked_are_refused(): void
    {
        $operator = $this->makeOperatorWith();
        $this->delegate('POST', '/users', ['email' => $operator->email, 'name' => 'Operator'])->assertStatus(409)->assertJsonPath('error.code', 'IDENTITY_NOT_LINKABLE');
        $this->assertDatabaseMissing('tenant_memberships', ['user_id' => $operator->id, 'tenant_id' => $this->tenant->id]);

        $disabled = User::factory()->create(['status' => User::STATUS_DISABLED]);
        $this->delegate('POST', '/users', ['email' => $disabled->email, 'name' => 'Disabled'])->assertStatus(409)->assertJsonPath('error.code', 'IDENTITY_NOT_LINKABLE');

        $held = $this->member(name: 'Held');
        TenantMembership::query()->where('user_id', $held->id)->update(['status' => TenantMembership::STATUS_SUSPENDED]);
        $this->delegate('POST', '/users', ['email' => $held->email, 'name' => 'Held'])->assertStatus(409)->assertJsonPath('error.code', 'MEMBERSHIP_NOT_ACTIVE');
    }

    public function test_a_user_is_not_created_when_a_requested_role_is_refused(): void
    {
        $foreignRole = $this->makeTenantRole($this->makeTenant(), ['entry-test.journal.view'], ['role_type' => Role::TYPE_APPLICATION, 'application_id' => $this->entry->id]);

        $this->delegate('POST', '/users', ['email' => 'ghost@example.test', 'name' => 'Ghost', 'role_ids' => [$foreignRole->id]])
            ->assertStatus(422)->assertJsonPath('error.code', 'ROLE_NOT_ASSIGNABLE');
        $this->assertNull(User::query()->where('email', 'ghost@example.test')->first());
    }

    public function test_listing_shows_only_this_tenants_users_of_this_application(): void
    {
        $mine = $this->member(name: 'Visible Person');
        $otherTenant = $this->makeTenant();
        $this->member(tenant: $otherTenant, name: 'Invisible Person');
        $this->member(access: false, name: 'No Access Person');

        $names = collect($this->delegate('GET', '/users')->assertOk()->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Visible Person'));
        $this->assertTrue($names->contains('Tenant Admin'));
        $this->assertFalse($names->contains('Invisible Person'));
        $this->assertFalse($names->contains('No Access Person'));
        $this->assertSame($mine->id, collect($this->delegate('GET', '/users', ['search' => 'visible'])->json('data'))->first()['id']);
    }

    public function test_setting_roles_replaces_delegated_roles_but_keeps_operator_decisions_and_other_applications(): void
    {
        $a = $this->createRole(['entry-test.journal.view'], 'A')->json('data.id');
        $b = $this->createRole(['entry-test.journal.post'], 'B')->json('data.id');
        $user = $this->member(name: 'Worker');

        $operatorOnly = $this->makeTenantRole($this->tenant, ['entry-test.period.close'], ['role_type' => Role::TYPE_APPLICATION, 'application_id' => $this->entry->id]);
        $otherAppRole = $this->makeTenantRole($this->tenant, ['other-test.thing.view'], ['role_type' => Role::TYPE_APPLICATION, 'application_id' => $this->otherApp->id]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $operatorOnly->id, 'tenant_id' => $this->tenant->id]);
        UserRole::create(['user_id' => $user->id, 'role_id' => $otherAppRole->id, 'tenant_id' => $this->tenant->id]);

        $this->delegate('PUT', "/users/{$user->id}/roles", ['role_ids' => [$a]])->assertOk();
        $this->delegate('PUT', "/users/{$user->id}/roles", ['role_ids' => [$b]])->assertOk();

        $held = UserRole::query()->where('user_id', $user->id)->pluck('role_id')->all();
        $this->assertEqualsCanonicalizing([$b, $operatorOnly->id, $otherAppRole->id], $held);
        $this->assertTrue(AuditLog::query()->where('action', 'delegation.user_roles_set')->exists());
    }

    public function test_roles_of_other_tenants_applications_or_operators_cannot_be_assigned(): void
    {
        $user = $this->member(name: 'Worker');
        $foreign = $this->makeTenantRole($this->makeTenant(), ['entry-test.journal.view'], ['role_type' => Role::TYPE_APPLICATION, 'application_id' => $this->entry->id]);
        $otherApp = $this->makeTenantRole($this->tenant, ['other-test.thing.view'], ['role_type' => Role::TYPE_APPLICATION, 'application_id' => $this->otherApp->id]);
        $notDelegable = $this->makeTenantRole($this->tenant, ['entry-test.period.close'], ['role_type' => Role::TYPE_APPLICATION, 'application_id' => $this->entry->id]);
        $system = Role::query()->where('code', 'PLATFORM_SUPERADMIN')->firstOrFail();

        foreach ([$foreign, $otherApp, $notDelegable, $system] as $role) {
            $this->delegate('PUT', "/users/{$user->id}/roles", ['role_ids' => [$role->id]])->assertStatus(422)->assertJsonPath('error.code', 'ROLE_NOT_ASSIGNABLE');
        }

        $this->assertSame(0, UserRole::query()->where('user_id', $user->id)->count());
    }

    public function test_an_application_template_role_inside_the_delegable_set_can_be_assigned(): void
    {
        $template = Role::create(['tenant_id' => null, 'application_id' => $this->entry->id, 'name' => 'Viewer template', 'code' => 'ENTRY_VIEWER', 'role_type' => Role::TYPE_APPLICATION, 'is_system' => true, 'status' => Role::STATUS_ACTIVE]);
        $template->permissions()->attach($this->perm['journal.view']->id, ['id' => (string) Str::uuid()]);
        $user = $this->member(name: 'Viewer');

        $this->delegate('PUT', "/users/{$user->id}/roles", ['role_ids' => [$template->id]])->assertOk();

        $this->assertDatabaseHas('user_roles', ['user_id' => $user->id, 'role_id' => $template->id, 'tenant_id' => $this->tenant->id]);
    }

    public function test_an_admin_tenant_is_protected_and_cannot_remove_themselves(): void
    {
        $roleId = $this->createRole()->json('data.id');
        $otherAdmin = $this->member(admin: true, name: 'Second admin');

        $this->delegate('PUT', "/users/{$otherAdmin->id}/roles", ['role_ids' => [$roleId]])->assertStatus(409)->assertJsonPath('error.code', 'TENANT_ADMIN_PROTECTED');
        $this->delegate('DELETE', "/users/{$otherAdmin->id}")->assertStatus(409)->assertJsonPath('error.code', 'TENANT_ADMIN_PROTECTED');
        $this->delegate('DELETE', "/users/{$this->admin->id}")->assertStatus(409);
        $this->assertSame(1, UserApplicationAccess::query()->where('user_id', $otherAdmin->id)->count());
    }

    public function test_users_outside_the_tenant_cannot_be_reached_by_id(): void
    {
        $roleId = $this->createRole()->json('data.id');
        $otherTenant = $this->makeTenant();
        $outsider = $this->member(tenant: $otherTenant, name: 'Outsider');

        $this->delegate('PUT', "/users/{$outsider->id}/roles", ['role_ids' => [$roleId]])->assertStatus(404)->assertJsonPath('error.code', 'USER_NOT_IN_TENANT');
        $this->delegate('DELETE', "/users/{$outsider->id}")->assertStatus(404);
        $this->assertDatabaseHas('user_application_access', ['user_id' => $outsider->id, 'tenant_id' => $otherTenant->id]);
    }

    public function test_revoking_a_user_removes_the_application_and_its_roles_but_keeps_the_identity(): void
    {
        $roleId = $this->createRole()->json('data.id');
        $user = $this->member(name: 'Leaver');
        $this->delegate('PUT', "/users/{$user->id}/roles", ['role_ids' => [$roleId]])->assertOk();

        $this->delegate('DELETE', "/users/{$user->id}")->assertOk();

        $this->assertDatabaseMissing('user_application_access', ['user_id' => $user->id, 'application_id' => $this->entry->id]);
        $this->assertSame(0, UserRole::query()->where('user_id', $user->id)->count());
        $this->assertNotNull(User::query()->find($user->id));
        $this->assertDatabaseHas('tenant_memberships', ['user_id' => $user->id, 'tenant_id' => $this->tenant->id]);
        $this->assertTrue(AuditLog::query()->where('action', 'delegation.user_access_revoked')->where('resource_id', $user->id)->exists());
    }

    // ------------------------------------------------------------ boundaries

    public function test_delegation_cannot_reach_the_ecosystem_catalog_or_entitlements(): void
    {
        $permissionCount = Permission::query()->count();
        $roleCount = Role::query()->count();
        $before = [$this->tenant->refresh()->status, $this->entry->refresh()->status, Entitlement::query()->count()];

        $this->delegate('POST', '/roles', ['name' => 'Grab', 'permissions' => ['cgo.tenant.update', 'cgo.application.update', 'cgo.role.create']])->assertStatus(422);
        $this->delegate('POST', '/users', ['email' => 'x@example.test', 'name' => 'X', 'is_tenant_admin' => true, 'tenant_id' => $this->makeTenant()->id])->assertCreated();

        $created = User::query()->where('email', 'x@example.test')->firstOrFail();
        $this->assertFalse((bool) TenantMembership::query()->where('user_id', $created->id)->value('is_tenant_admin'));
        $this->assertSame(0, TenantMembership::query()->where('user_id', $created->id)->where('tenant_id', '!=', $this->tenant->id)->count());
        $this->assertSame($permissionCount, Permission::query()->count());
        $this->assertSame($roleCount, Role::query()->count());
        $this->assertSame($before, [$this->tenant->refresh()->status, $this->entry->refresh()->status, Entitlement::query()->count()]);
        $this->assertFalse(app(AuthorizationService::class)->hasEcosystemAccess($created));
    }

    public function test_every_delegated_change_is_audited_with_the_acting_admin_and_the_product(): void
    {
        $this->createRole()->assertCreated();
        $this->delegate('POST', '/users', ['email' => 'audited@example.test', 'name' => 'Audited'])->assertCreated();

        $logs = AuditLog::query()->whereIn('action', ['delegation.role_created', 'delegation.user_created'])->get();

        $this->assertCount(2, $logs);
        foreach ($logs as $log) {
            $this->assertSame($this->admin->id, $log->actor_user_id);
            $this->assertSame($this->tenant->id, $log->tenant_id);
            $this->assertSame($this->entry->id, $log->application_id);
            $this->assertSame($this->account->id, $log->metadata['service_account_id']);
        }
    }

    public function test_the_ecosystem_portal_stays_closed_to_the_admin_tenant_who_delegates(): void
    {
        $this->actingAsSanctum($this->admin);

        $this->getJson("/api/v1/tenants/{$this->tenant->id}")->assertStatus(403)->assertJsonPath('error.code', 'ECOSYSTEM_ACCESS_REQUIRED');
        $this->getJson('/api/v1/users')->assertStatus(403)->assertJsonPath('error.code', 'ECOSYSTEM_ACCESS_REQUIRED');
    }

    private function actingAsSanctum(User $user): void
    {
        Sanctum::actingAs($user);
    }
}
