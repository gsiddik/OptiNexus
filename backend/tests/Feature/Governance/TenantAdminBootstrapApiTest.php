<?php

namespace Tests\Feature\Governance;

use App\Jobs\SendAccountCredential;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

/**
 * First Admin Tenant created by an operator, no e-mail (owner decision 2026-10-18, second batch). The operator API against a real
 * PostgreSQL database: creation, manual e-mail verification, reissue, and every refusal around them. The browser steps (sign-in,
 * password choice) are in AccountActivationFlowTest.
 */
class TenantAdminBootstrapApiTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    private const GRANTS = ['cgo.tenant.view', 'cgo.tenant.admin.assign', 'cgo.user.create', 'cgo.user.tenant.assign', 'cgo.user.application.assign', 'cgo.user.role.assign', 'cgo.user.update', 'cgo.user.view', 'cgo.user.activate', 'cgo.user.email.verify'];

    private Tenant $tenant;

    private Application $fleet;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Queue::fake();

        $this->seedGovernanceBaseline();
        $this->fleet = $this->makeApplication(['application_code' => 'fleet-test', 'name' => 'Fleet Test']);
        $this->tenant = $this->makeTenant(null, ['name' => 'Acme']);
        $this->tenant->applications()->attach($this->fleet->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $this->operator = $this->makeOperatorWith(self::GRANTS);
        Sanctum::actingAs($this->operator);
    }

    private function url(string $suffix = '', ?Tenant $tenant = null): string
    {
        return '/api/v1/ecosystem/tenants/'.($tenant ?? $this->tenant)->id.'/admin-accounts'.$suffix;
    }

    /** @param  array<string, mixed>  $extra */
    private function create(array $extra = [], string $email = 'Acme.Admin@Example.Test'): TestResponse
    {
        return $this->postJson($this->url(), $extra + ['name' => 'Acme Admin', 'email' => $email, 'application_ids' => [$this->fleet->id]]);
    }

    private function created(): array
    {
        $response = $this->create()->assertCreated();

        return [User::query()->findOrFail($response->json('data.user.id')), $response->json('data.temporary_credential.password'), $response];
    }

    private function verify(User $user, ?string $reason = 'Called the registered office number and read the code back, 2026-10-18', ?string $email = null): TestResponse
    {
        return $this->postJson($this->url("/{$user->id}/email-verification"), ['email' => $email ?? $user->email, 'reason' => $reason]);
    }

    private function cgoRole(array $permissionKeys, string $code = 'FLEET_ADMIN'): Role
    {
        return $this->makeTenantRole($this->tenant, $permissionKeys, ['code' => $code, 'role_type' => Role::TYPE_APPLICATION, 'application_id' => $this->fleet->id]);
    }

    // ---------------------------------------------------------------- creation

    public function test_operator_creates_an_unverified_account_with_a_temporary_password_shown_once(): void
    {
        $role = $this->cgoRole(['cgo.tenant.view']);

        $response = $this->create(['role_ids' => [$role->id]])->assertCreated();
        $user = User::query()->findOrFail($response->json('data.user.id'));
        $plain = $response->json('data.temporary_credential.password');

        $this->assertSame('acme.admin@example.test', $user->email, 'e-mail is stored lower case');
        $this->assertSame(User::STATUS_INVITED, $user->status, 'no access anywhere until the activation is complete');
        $this->assertNull($user->email_verified_at, 'created by an operator is not verified');
        $this->assertNotNull($user->activation_started_at);
        $this->assertTrue($user->password_change_required);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{5}(-[A-HJ-NP-Z2-9]{5}){3}$/', $plain);
        $this->assertTrue($response->json('data.temporary_credential.shown_once'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('created', $response->json('data.outcome'));
        $this->assertSame(User::ACTIVATION_PASSWORD_CHANGE_REQUIRED, $response->json('data.activation.state'));
        $this->assertSame(['verify_email', 'hand_over_credential'], $response->json('data.activation.next_steps'));

        // The database holds a hash of the canonical form, never the displayed text.
        $this->assertNotSame($plain, $user->password);
        $this->assertTrue(Hash::check(str_replace('-', '', $plain), $user->password));
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $user->temporary_password_expires_at->timestamp, 5);

        $membership = TenantMembership::query()->where('user_id', $user->id)->where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertTrue($membership->is_tenant_admin);
        $this->assertSame(1, UserApplicationAccess::query()->where('user_id', $user->id)->where('tenant_id', $this->tenant->id)->where('application_id', $this->fleet->id)->count());
        $this->assertSame([$role->id], $user->userRoles()->where('tenant_id', $this->tenant->id)->pluck('role_id')->all());

        $audit = AuditLog::query()->where('action', 'tenant_admin.bootstrap_created')->firstOrFail();
        $this->assertSame($this->operator->id, $audit->actor_user_id);
        $this->assertSame($user->id, $audit->resource_id);
        $this->assertSame(['fleet-test'], $audit->new_value['applications']);
    }

    public function test_the_temporary_password_appears_in_no_audit_record_listing_or_user_resource(): void
    {
        [$user, $plain] = $this->created();
        $canonical = str_replace('-', '', $plain);

        $this->verify($user)->assertOk();
        $this->postJson($this->url("/{$user->id}/temporary-credential"))->assertOk();
        $this->getJson($this->url())->assertOk();
        $this->getJson("/api/v1/users/{$user->id}")->assertOk();

        $everything = AuditLog::query()->get()->toJson().json_encode($user->fresh()->getAttributes());
        foreach ([$plain, $canonical] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
            $this->assertStringNotContainsString($secret, $this->getJson($this->url())->getContent());
            $this->assertStringNotContainsString($secret, $this->getJson("/api/v1/users/{$user->id}")->getContent());
        }

        $log = storage_path('logs/laravel.log');
        $this->assertTrue(! is_file($log) || ! str_contains((string) file_get_contents($log), $canonical));
    }

    public function test_bootstrap_and_reissue_are_out_of_band_but_email_change_queues_its_credential(): void
    {
        [$user] = $this->created();
        $this->verify($user)->assertOk();
        $this->postJson($this->url("/{$user->id}/temporary-credential"))->assertOk();
        Queue::assertNothingPushed();
        $this->putJson("/api/v1/users/{$user->id}", ['email' => 'moved@example.test'])->assertOk();

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertPushed(SendAccountCredential::class, 1);
        Queue::assertCount(1);
    }

    public function test_listing_shows_where_each_admin_stands(): void
    {
        [$user] = $this->created();

        $row = $this->getJson($this->url())->assertOk()->json('data.0');
        $this->assertSame($user->id, $row['user']['id']);
        $this->assertSame(['verify_email', 'hand_over_credential'], $row['activation']['next_steps']);
        $this->assertFalse($row['activation']['email']['verified']);
        $this->assertTrue($row['activation']['password']['change_required']);
        $this->assertSame('fleet-test', $row['applications'][0]['code']);
    }

    // ---------------------------------------------------------------- who may call it

    public function test_tenant_users_and_admin_tenants_cannot_use_any_of_it(): void
    {
        [$user] = $this->created();
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($admin, $this->tenant, $this->makeTenantRole($this->tenant, ['cgo.user.email.verify', 'cgo.tenant.admin.assign', 'cgo.user.create']), isTenantAdmin: true);
        Sanctum::actingAs($admin);

        $this->getJson($this->url())->assertForbidden()->assertJsonPath('error.code', 'ECOSYSTEM_ACCESS_REQUIRED');
        $this->create(email: 'other@example.test')->assertForbidden()->assertJsonPath('error.code', 'ECOSYSTEM_ACCESS_REQUIRED');
        $this->verify($user)->assertForbidden()->assertJsonPath('error.code', 'ECOSYSTEM_ACCESS_REQUIRED');
        $this->postJson($this->url("/{$user->id}/temporary-credential"))->assertForbidden()->assertJsonPath('error.code', 'ECOSYSTEM_ACCESS_REQUIRED');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verification_needs_its_own_permission_and_an_operator_without_it_is_refused(): void
    {
        [$user] = $this->created();
        Sanctum::actingAs($this->makeOperatorWith(array_values(array_diff(self::GRANTS, ['cgo.user.email.verify']))));

        $this->verify($user)->assertForbidden()->assertJsonPath('error.code', 'UNAUTHORIZED');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_the_platform_administrator_role_does_not_carry_the_verification_permission_by_default(): void
    {
        $permission = Permission::query()->where('permission_key', 'cgo.user.email.verify')->firstOrFail();
        $holders = Role::query()->where('is_system', true)->whereHas('permissions', fn ($q) => $q->whereKey($permission->id))->pluck('code')->all();

        $this->assertSame(['PLATFORM_SUPERADMIN'], $holders);
    }

    public function test_an_operator_cannot_grant_more_than_they_may_grant_themselves(): void
    {
        foreach (['cgo.user.create', 'cgo.user.tenant.assign', 'cgo.user.application.assign'] as $missing) {
            Sanctum::actingAs($this->makeOperatorWith(array_values(array_diff(self::GRANTS, [$missing]))));

            $this->create()->assertForbidden()->assertJsonPath('error.code', 'PRIVILEGE_ESCALATION_DENIED')
                ->assertJsonPath('error.details.missing_permissions.0', $missing);
        }

        $this->assertSame(0, User::query()->where('email', 'acme.admin@example.test')->count());
    }

    public function test_a_role_beyond_the_operators_own_permissions_is_refused(): void
    {
        $permission = $this->makePermission($this->fleet, 'fleet-test.vehicle.delete');
        $role = $this->cgoRole(['fleet-test.vehicle.delete'], 'FLEET_DELETE');
        $this->assertNotNull($permission);

        $this->create(['role_ids' => [$role->id]])->assertForbidden()
            ->assertJsonPath('error.code', 'PRIVILEGE_ESCALATION_DENIED')->assertJsonPath('error.details.role_ids.0', $role->id);
        $this->assertSame(0, User::query()->where('email', 'acme.admin@example.test')->count());
    }

    // ---------------------------------------------------------------- tenant and entitlement boundaries

    public function test_only_subscribed_applications_and_this_tenants_roles_can_be_given(): void
    {
        $unassigned = $this->makeApplication(['application_code' => 'other-test']);
        $this->create(['application_ids' => [$unassigned->id]])->assertStatus(422)->assertJsonPath('error.code', 'APPLICATION_NOT_SUBSCRIBED');

        $governance = Application::query()->where('application_code', 'cgo')->firstOrFail();
        $this->tenant->applications()->attach($governance->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $this->create(['application_ids' => [$governance->id]])->assertStatus(422)->assertJsonPath('error.code', 'APPLICATION_NOT_SUBSCRIBED');

        $otherTenant = $this->makeTenant();
        $foreignRole = $this->makeTenantRole($otherTenant, ['cgo.tenant.view'], ['code' => 'FOREIGN']);
        $platformRole = Role::query()->where('code', 'AUDITOR')->firstOrFail();
        $this->create(['role_ids' => [$foreignRole->id]])->assertStatus(422)->assertJsonPath('error.code', 'ROLE_NOT_ASSIGNABLE');
        $this->create(['role_ids' => [$platformRole->id]])->assertStatus(422)->assertJsonPath('error.code', 'ROLE_NOT_ASSIGNABLE');

        $this->assertSame(0, User::query()->where('email', 'acme.admin@example.test')->count());
    }

    public function test_an_inactive_tenant_cannot_get_an_admin(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->create()->assertStatus(409)->assertJsonPath('error.code', 'TENANT_NOT_ACTIVE');
    }

    public function test_verify_and_reissue_only_reach_accounts_of_that_tenant(): void
    {
        [$user] = $this->created();
        $otherTenant = $this->makeTenant();
        $this->postJson($this->url("/{$user->id}/email-verification", $otherTenant), ['email' => $user->email, 'reason' => 'Checked by phone call, number from the contract'])
            ->assertNotFound()->assertJsonPath('error.code', 'USER_NOT_IN_TENANT');
        $this->postJson($this->url("/{$user->id}/temporary-credential", $otherTenant))->assertNotFound()->assertJsonPath('error.code', 'USER_NOT_IN_TENANT');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    // ---------------------------------------------------------------- identities

    public function test_an_existing_e_mail_is_a_conflict_and_is_never_adopted_or_duplicated(): void
    {
        $existing = User::factory()->create(['email' => 'taken@example.test', 'status' => User::STATUS_ACTIVE, 'password' => 'Existing!Passw0rd']);
        $hash = $existing->password;

        $this->create(email: 'Taken@Example.Test')->assertStatus(409)->assertJsonPath('error.code', 'IDENTITY_CONFLICT')
            ->assertJsonPath('error.details.existing_user_id', $existing->id);

        $this->assertSame(1, User::withTrashed()->whereRaw('lower(email) = ?', ['taken@example.test'])->count());
        $this->assertSame(0, TenantMembership::query()->where('user_id', $existing->id)->count(), 'not linked by e-mail alone');
        $this->assertSame($hash, $existing->fresh()->password);
    }

    public function test_a_deleted_identity_is_a_conflict_too(): void
    {
        $gone = User::factory()->create(['email' => 'gone@example.test']);
        $gone->delete();

        $this->create(email: 'gone@example.test')->assertStatus(409)->assertJsonPath('error.code', 'IDENTITY_CONFLICT')->assertJsonPath('error.details.deleted', true);
    }

    public function test_linking_an_existing_identity_keeps_its_password_status_and_history(): void
    {
        $existing = User::factory()->create(['email' => 'veteran@example.test', 'status' => User::STATUS_ACTIVE, 'password' => 'Existing!Passw0rd', 'last_login_at' => now()->subDay()]);
        $before = $existing->fresh()->only(['id', 'password', 'status', 'email_verified_at', 'last_login_at']);
        $other = $this->makeTenant($this->tenant->customer);
        $this->attachUserToTenant($existing, $other);

        $response = $this->postJson($this->url(), ['user_id' => $existing->id, 'confirm_email' => 'VETERAN@example.test', 'application_ids' => [$this->fleet->id]])->assertCreated();

        $this->assertSame('linked', $response->json('data.outcome'));
        $this->assertNull($response->json('data.temporary_credential'), 'no credential is shown or changed for an existing identity');
        $this->assertEquals($before, $existing->fresh()->only(array_keys($before)));
        $this->assertFalse($existing->fresh()->password_change_required);
        $this->assertNull($existing->fresh()->activation_started_at);
        $this->assertTrue(TenantMembership::query()->where('user_id', $existing->id)->where('tenant_id', $this->tenant->id)->value('is_tenant_admin'));
        $this->assertSame(1, TenantMembership::query()->where('user_id', $existing->id)->where('tenant_id', $other->id)->count(), 'the other tenant is untouched');
        $this->assertSame(1, User::query()->where('email', 'veteran@example.test')->count());
        $this->assertNotNull(AuditLog::query()->where('action', 'tenant_admin.bootstrap_linked')->first());
    }

    public function test_an_existing_identity_must_be_named_and_confirmed_and_be_linkable(): void
    {
        $existing = User::factory()->create(['email' => 'veteran@example.test', 'status' => User::STATUS_ACTIVE]);
        $link = fn (User $u, string $email) => $this->postJson($this->url(), ['user_id' => $u->id, 'confirm_email' => $email, 'application_ids' => [$this->fleet->id]]);

        $link($existing, 'someone.else@example.test')->assertStatus(422)->assertJsonPath('error.code', 'IDENTITY_NOT_LINKABLE');
        $this->postJson($this->url(), ['user_id' => $existing->id, 'name' => 'x', 'application_ids' => [$this->fleet->id]])->assertStatus(422);
        $this->postJson($this->url(), ['user_id' => (string) Str::uuid(), 'confirm_email' => 'x@example.test', 'application_ids' => [$this->fleet->id]])->assertNotFound();

        foreach ([User::STATUS_SUSPENDED, User::STATUS_DISABLED, User::STATUS_INVITED] as $status) {
            $existing->forceFill(['status' => $status])->save();
            $link($existing, 'veteran@example.test')->assertStatus(409)->assertJsonPath('error.code', 'IDENTITY_NOT_LINKABLE');
        }

        $existing->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $existing->delete();
        $link($existing, 'veteran@example.test')->assertStatus(409)->assertJsonPath('error.code', 'IDENTITY_NOT_LINKABLE');
        $existing->restore();

        $this->assertSame(0, TenantMembership::query()->where('user_id', $existing->id)->count());
    }

    public function test_a_platform_operator_cannot_be_made_an_admin_tenant(): void
    {
        $target = $this->makeOperatorWith([]);

        $this->postJson($this->url(), ['user_id' => $target->id, 'confirm_email' => $target->email, 'application_ids' => [$this->fleet->id]])
            ->assertStatus(409)->assertJsonPath('error.code', 'IDENTITY_NOT_LINKABLE');
    }

    public function test_a_suspended_membership_is_not_silently_reactivated(): void
    {
        $existing = User::factory()->create(['email' => 'veteran@example.test', 'status' => User::STATUS_ACTIVE]);
        TenantMembership::create(['user_id' => $existing->id, 'tenant_id' => $this->tenant->id, 'status' => TenantMembership::STATUS_SUSPENDED, 'is_tenant_admin' => false]);

        $this->postJson($this->url(), ['user_id' => $existing->id, 'confirm_email' => $existing->email, 'application_ids' => [$this->fleet->id]])
            ->assertStatus(409)->assertJsonPath('error.code', 'MEMBERSHIP_NOT_ACTIVE');
        $this->assertSame(TenantMembership::STATUS_SUSPENDED, TenantMembership::query()->where('user_id', $existing->id)->value('status'));
    }

    // ---------------------------------------------------------------- manual verification

    public function test_manual_verification_records_who_what_when_and_why_and_audits_it(): void
    {
        [$user] = $this->created();
        $this->travelTo(now()->addMinutes(5));

        $response = $this->verify($user, 'Called the registered office number and read the code back')->assertOk();

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(User::EMAIL_VERIFIED_BY_OPERATOR, $user->email_verification_method);
        $this->assertSame($this->operator->id, $user->email_verified_by);
        $this->assertTrue($response->json('data.activation.email.verified'));
        $this->assertSame($this->operator->id, $response->json('data.activation.email.verified_by.id'));
        $this->assertSame(User::STATUS_INVITED, $user->status, 'verified is only half of the activation: the password is still temporary');

        $audit = AuditLog::query()->where('action', 'user.email_verified_manually')->firstOrFail();
        $this->assertSame($this->operator->id, $audit->actor_user_id);
        $this->assertSame($user->id, $audit->resource_id);
        $this->assertSame($user->email, $audit->new_value['verified_email']);
        $this->assertSame('Called the registered office number and read the code back', $audit->metadata['reason']);
        $this->assertNotNull($audit->new_value['verified_at']);
    }

    public function test_verification_requires_a_reason_the_current_address_and_a_member_account(): void
    {
        [$user] = $this->created();

        $this->postJson($this->url("/{$user->id}/email-verification"), ['email' => $user->email])->assertStatus(422);
        $this->verify($user, 'too short')->assertStatus(422);
        $this->verify($user, str_repeat('x', 501))->assertStatus(422);
        $this->verify($user, email: 'not-the-address@example.test')->assertStatus(422)->assertJsonPath('error.code', 'EMAIL_MISMATCH');
        $this->assertNull($user->fresh()->email_verified_at);

        $this->verify($user, email: strtoupper($user->email))->assertOk();
        $this->verify($user)->assertStatus(409)->assertJsonPath('error.code', 'EMAIL_ALREADY_VERIFIED');
        $this->assertSame(1, AuditLog::query()->where('action', 'user.email_verified_manually')->count());
    }

    public function test_nobody_verifies_their_own_address_through_the_operator_api(): void
    {
        $this->postJson($this->url("/{$this->operator->id}/email-verification"), ['email' => $this->operator->email, 'reason' => 'I checked myself, honestly'])
            ->assertForbidden()->assertJsonPath('error.code', 'SELF_VERIFICATION_DENIED');
        $this->assertNull(AuditLog::query()->where('action', 'user.email_verified_manually')->first());
        $this->assertNotNull(AuditLog::query()->where('action', 'user.email_verification_denied')->first());
    }

    public function test_suspended_or_disabled_accounts_cannot_be_verified(): void
    {
        [$user] = $this->created();
        $user->forceFill(['status' => User::STATUS_DISABLED])->save();

        $this->verify($user)->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_NOT_ELIGIBLE');
    }

    // ---------------------------------------------------------------- e-mail change

    public function test_changing_the_e_mail_drops_the_verification_and_it_must_be_recorded_again_for_the_new_address(): void
    {
        [$user] = $this->created();
        $this->verify($user)->assertOk();

        $this->putJson("/api/v1/users/{$user->id}", ['email' => 'new.address@example.test'])->assertOk()->assertJsonPath('data.email_verified_at', null);

        $user->refresh();
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->email_verification_method);
        $this->assertNull($user->email_verified_by);
        $audit = AuditLog::query()->where('action', 'user.email_verification_revoked')->firstOrFail();
        $this->assertSame('required', $audit->metadata['reverification']);
        $this->assertSame('new.address@example.test', $audit->new_value['email']);

        $this->verify($user, email: 'acme.admin@example.test')->assertStatus(422)->assertJsonPath('error.code', 'EMAIL_MISMATCH');
        $this->verify($user, email: 'new.address@example.test')->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_name_change_or_a_case_only_e_mail_change_keeps_the_verification(): void
    {
        [$user] = $this->created();
        $this->verify($user)->assertOk();

        $this->putJson("/api/v1/users/{$user->id}", ['name' => 'Renamed Admin'])->assertOk();
        $this->putJson("/api/v1/users/{$user->id}", ['email' => 'ACME.ADMIN@example.test'])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNull(AuditLog::query()->where('action', 'user.email_verification_revoked')->first());
    }

    // ---------------------------------------------------------------- reissue and manual activation

    public function test_reissue_replaces_the_temporary_password_while_activation_is_open(): void
    {
        [$user, $first] = $this->created();

        $response = $this->postJson($this->url("/{$user->id}/temporary-credential"))->assertOk();
        $second = $response->json('data.temporary_credential.password');

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $user->refresh();
        $this->assertFalse(Hash::check(str_replace('-', '', $first), $user->password), 'the old temporary password stops working');
        $this->assertTrue(Hash::check(str_replace('-', '', $second), $user->password));
        $this->assertNotNull(AuditLog::query()->where('action', 'user.temporary_password_reissued')->first());
    }

    public function test_reissue_preserves_pending_verification_but_resets_an_active_tenant_administrator(): void
    {
        [$user] = $this->created();
        $user->forceFill(['password' => 'Chosen!ByTheHolder1', 'password_change_required' => false, 'temporary_password_expires_at' => null, 'password_changed_at' => now()])->save();
        $hash = $user->fresh()->password;

        $this->postJson($this->url("/{$user->id}/temporary-credential"))->assertStatus(409)->assertJsonPath('error.code', 'PASSWORD_ALREADY_CHOSEN');

        $user->forceFill(['status' => User::STATUS_ACTIVE, 'activated_at' => now()])->save();
        $this->postJson($this->url("/{$user->id}/temporary-credential"))->assertOk()->assertJsonPath('data.activation.password.change_required', true);
        $this->assertNotSame($hash, $user->fresh()->password);

        $plain = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($plain, $this->tenant, isTenantAdmin: true);
        $this->postJson($this->url("/{$plain->id}/temporary-credential"))->assertOk()->assertJsonPath('data.activation.password.change_required', true);
    }

    public function test_an_account_in_activation_cannot_be_activated_by_hand_or_through_another_door(): void
    {
        [$user] = $this->created();

        $this->postJson("/api/v1/users/{$user->id}/activate")->assertStatus(409)->assertJsonPath('error.code', 'ACTIVATION_NOT_COMPLETE');
        $this->assertSame(User::STATUS_INVITED, $user->fresh()->status);
        $this->assertTrue($user->fresh()->isInManagedActivation());
    }

    public function test_the_onboarding_report_names_the_activation_gap(): void
    {
        $this->created();

        $check = collect($this->getJson('/api/v1/ecosystem/onboarding/tenants/'.$this->tenant->id)->assertOk()->json('data.checks'))->firstWhere('key', 'tenant.admin');

        $this->assertSame('MISSING', $check['status']);
        $this->assertSame('tenant_admin_activation_pending', $check['message_code']);
        $this->assertSame(1, $check['detail']['pending_activation']);
    }
}
