<?php

namespace Tests\Feature\Governance;

use App\Jobs\SendUserInvitation;
use App\Mail\UserInvitationMail;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserInvitation;
use App\Models\UserRole;
use App\Services\AuditService;
use App\Services\Delegation\InvitationService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesInvitationFixtures;
use Tests\TestCase;

/**
 * Onboarding by invitation (owner decision 2026-10-18), the product-facing half: an Admin Tenant invites, lists, resends and revokes
 * through the delegation API. Creating an invitation grants nothing; the token exists only in the e-mail.
 */
class InvitationApiTest extends TestCase
{
    use CreatesInvitationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInvitationWorld();
    }

    public function test_an_invitation_is_created_mailed_and_grants_nothing_yet(): void
    {
        $response = $this->invite('Ann.Lee@Example.Test')->assertCreated();

        $response->assertJsonPath('data.email', 'ann.lee@example.test')
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.delivery.status', 'SENT')
            ->assertJsonPath('data.role_ids.0', $this->clerk->id)
            ->assertJsonPath('data.can_resend', true)
            ->assertJsonPath('data.can_revoke', true);
        $this->assertSame($this->admin->id, $response->json('data.invited_by.id'));

        $token = $this->tokenSentTo('ann.lee@example.test');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);

        $row = $this->invitationFor('ann.lee@example.test');
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertTrue($row->expires_at->isAfter(now()->addHours(71)));

        // Nothing is granted by an invitation: no identity, membership, access or role exists yet.
        $this->assertSame(0, User::query()->where('email', 'ann.lee@example.test')->count());
        $this->assertSame(1, TenantMembership::query()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, UserRole::query()->where('tenant_id', $this->tenant->id)->count());

        // The token appears nowhere in the API answer, the list, the audit trail or the stored row.
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertStringNotContainsString($token, $this->delegate('GET', '/invitations')->getContent());
        $this->assertStringNotContainsString($token, AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString($token, json_encode($row->getAttributes()));
        $this->assertArrayNotHasKey('token_hash', $response->json('data'));

        foreach (['invitation.created', 'invitation.sent'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->where('resource_id', $row->id)->exists(), $action);
        }
    }

    public function test_the_mail_is_in_the_requested_language_and_carries_the_link_only_to_the_invitee(): void
    {
        $this->invite('budi@example.test', ['locale' => 'id', 'name' => 'Budi'])->assertCreated()->assertJsonPath('data.locale', 'id');

        Mail::assertSent(UserInvitationMail::class, function (UserInvitationMail $mail): bool {
            $mail->assertHasSubject('Anda diundang ke Entry Test (Acme Test) di OptiNexus');
            $mail->assertSeeInHtml('Halo Budi');
            $mail->assertSeeInText('Tautan hanya berlaku sekali');
            $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/invitations/', $mail->link);

            return $mail->hasTo('budi@example.test') && count($mail->to) === 1 && $mail->cc === [] && $mail->bcc === [];
        });
    }

    public function test_roles_outside_the_delegable_set_refuse_the_whole_invitation(): void
    {
        $notDelegable = $this->applicationRole($this->tenant, 'CLOSER', ['entry-test.period.close']);
        $otherTenant = $this->makeTenant();
        $foreignRole = $this->applicationRole($otherTenant, 'FOREIGN', ['entry-test.journal.view']);

        foreach ([[$notDelegable->id], [$foreignRole->id], [Str::uuid()->toString()], [$this->clerk->id, $notDelegable->id]] as $roleIds) {
            $this->invite('x@example.test', ['role_ids' => $roleIds])->assertStatus(422)->assertJsonPath('error.code', 'ROLE_NOT_ASSIGNABLE');
        }

        $this->assertSame(0, UserInvitation::query()->count());
        Mail::assertNothingSent();
    }

    public function test_only_an_admin_tenant_of_this_tenant_and_product_may_invite(): void
    {
        $plain = $this->member(name: 'Plain');
        $noAccess = $this->member(admin: true, access: false, name: 'Admin without the product');
        $otherTenant = $this->makeTenant();
        $otherTenant->applications()->attach($this->entry->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $foreign = $this->member(admin: true, tenant: $otherTenant, name: 'Foreign admin');

        foreach ([$plain, $noAccess, $foreign, User::factory()->create()] as $actor) {
            $this->invite('x@example.test', [], $actor)->assertStatus(403)->assertJsonPath('error.code', 'ACTOR_NOT_TENANT_ADMIN');
            $this->delegate('GET', '/invitations', [], $actor)->assertStatus(403);
        }

        $this->assertSame(0, UserInvitation::query()->count());
    }

    public function test_an_invitation_of_another_tenant_or_product_cannot_be_seen_resent_or_revoked(): void
    {
        $id = $this->invite()->json('data.id');

        $otherTenant = $this->makeTenant();
        $otherTenant->applications()->attach($this->entry->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $foreign = $this->member(admin: true, tenant: $otherTenant, name: 'Foreign admin');

        $this->delegate('POST', "/invitations/{$id}/resend", [], $foreign, null, $otherTenant)->assertStatus(404)->assertJsonPath('error.code', 'INVITATION_NOT_FOUND');
        $this->delegate('POST', "/invitations/{$id}/revoke", [], $foreign, null, $otherTenant)->assertStatus(404);
        $this->assertCount(0, $this->delegate('GET', '/invitations', [], $foreign, null, $otherTenant)->assertOk()->json('data'));

        // Another product of the same tenant has its own invitations.
        $this->tenant->applications()->attach($this->otherApp->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $otherAppAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        TenantMembership::create(['user_id' => $otherAppAdmin->id, 'tenant_id' => $this->tenant->id, 'status' => 'ACTIVE', 'is_tenant_admin' => true]);
        UserApplicationAccess::create(['user_id' => $otherAppAdmin->id, 'tenant_id' => $this->tenant->id, 'application_id' => $this->otherApp->id, 'status' => 'ACTIVE']);
        [$otherToken] = $this->issueServiceAccountTokenFor($this->otherApp->id, ['tenant.delegate'], null, 'other-product');

        $this->delegate('POST', "/invitations/{$id}/revoke", [], $otherAppAdmin, $otherToken)->assertStatus(404);
        $this->assertSame(UserInvitation::STATUS_PENDING, UserInvitation::query()->findOrFail($id)->status);
    }

    public function test_a_person_with_access_already_and_a_second_open_invitation_are_refused(): void
    {
        $this->member(name: 'Existing', user: ['email' => 'already@example.test']);
        $this->invite('Already@Example.test')->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_MEMBER');

        $first = $this->invite('dup@example.test')->assertCreated()->json('data.id');
        $this->invite('DUP@example.test')->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_PENDING')->assertJsonPath('error.details.invitation_id', $first);
        $this->assertSame(1, UserInvitation::query()->where('email', 'dup@example.test')->count());
    }

    public function test_creating_never_reveals_whether_the_e_mail_belongs_to_an_identity_elsewhere(): void
    {
        User::factory()->create(['email' => 'operator@example.test', 'status' => User::STATUS_DISABLED]);
        User::factory()->create(['email' => 'elsewhere@example.test']);

        $this->invite('operator@example.test')->assertCreated();
        $this->invite('elsewhere@example.test')->assertCreated();
    }

    public function test_resend_replaces_the_link_resets_the_expiry_and_respects_cooldown_and_limit(): void
    {
        $id = $this->invite('r@example.test')->assertCreated()->json('data.id');
        $old = $this->tokenSentTo('r@example.test');

        $this->delegate('POST', "/invitations/{$id}/resend")->assertStatus(429)->assertJsonPath('error.code', 'INVITATION_RESEND_COOLDOWN')->assertHeader('Retry-After');

        $this->travel(2)->minutes();
        $this->delegate('POST', "/invitations/{$id}/resend")->assertOk()->assertJsonPath('data.send_count', 2)->assertJsonPath('data.delivery.status', 'SENT');
        $new = $this->tokenSentTo('r@example.test');

        $this->assertNotSame($old, $new);
        $row = UserInvitation::query()->findOrFail($id);
        $this->assertSame(hash('sha256', $new), $row->token_hash);
        $this->assertNull(app(InvitationService::class)->findByToken($old), 'the old link must be dead');
        $this->assertNotNull(app(InvitationService::class)->findByToken($new));

        config(['invitations.max_resends' => 2]);
        $this->travel(2)->minutes();
        $this->delegate('POST', "/invitations/{$id}/resend")->assertOk();
        $this->travel(2)->minutes();
        $this->delegate('POST', "/invitations/{$id}/resend")->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_RESEND_LIMIT');
        $this->assertTrue(AuditLog::query()->where('action', 'invitation.resent')->where('resource_id', $id)->exists());
    }

    public function test_an_expired_invitation_is_reported_as_expired_and_resend_revives_it(): void
    {
        $id = $this->invite('late@example.test')->assertCreated()->json('data.id');

        $this->travel(73)->hours();
        $this->delegate('GET', '/invitations')->assertJsonPath('data.0.status', 'EXPIRED')->assertJsonPath('data.0.can_resend', true);
        $this->delegate('GET', '/invitations?status=EXPIRED')->assertJsonCount(1, 'data');
        $this->delegate('GET', '/invitations?status=PENDING')->assertJsonCount(0, 'data');

        // A new invitation for the same person is possible once the old one ran out, and sweeping stamps the old one.
        $this->delegate('POST', "/invitations/{$id}/resend")->assertOk()->assertJsonPath('data.status', 'PENDING');
        $this->assertSame(0, app(InvitationService::class)->expireStale());
        $this->assertTrue(UserInvitation::query()->findOrFail($id)->isOpen());
    }

    public function test_the_sweeper_stamps_expired_invitations_and_a_new_invitation_replaces_an_expired_one(): void
    {
        $this->invite('sweep@example.test')->assertCreated();
        $this->travel(73)->hours();

        $this->invite('sweep@example.test')->assertCreated();

        $this->assertSame(1, UserInvitation::query()->where('status', 'EXPIRED')->count());
        $this->assertSame(1, UserInvitation::query()->where('status', 'PENDING')->count());
        $this->assertTrue(AuditLog::query()->where('action', 'invitation.expired')->exists());
    }

    public function test_revoke_is_final_idempotent_and_audited_and_an_accepted_invitation_cannot_be_changed(): void
    {
        $id = $this->invite('rev@example.test')->assertCreated()->json('data.id');

        $this->delegate('POST', "/invitations/{$id}/revoke", ['reason' => 'Wrong person'])->assertOk()
            ->assertJsonPath('data.status', 'REVOKED')->assertJsonPath('data.can_resend', false)->assertJsonPath('data.can_revoke', false);
        $this->delegate('POST', "/invitations/{$id}/revoke")->assertOk()->assertJsonPath('data.status', 'REVOKED');
        $this->delegate('POST', "/invitations/{$id}/resend")->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_NOT_PENDING')->assertJsonPath('error.details.status', 'REVOKED');
        $this->assertSame(1, AuditLog::query()->where('action', 'invitation.revoked')->where('resource_id', $id)->count());

        // A new invitation for the same person is allowed after a revoke.
        $this->invite('rev@example.test')->assertCreated();

        $accepted = $this->invite('done@example.test')->json('data.id');
        UserInvitation::query()->whereKey($accepted)->update(['status' => 'ACCEPTED', 'accepted_at' => now()]);
        $this->delegate('POST', "/invitations/{$accepted}/revoke")->assertStatus(409)->assertJsonPath('error.details.status', 'ACCEPTED');
        $this->delegate('POST', "/invitations/{$accepted}/resend")->assertStatus(409);
    }

    public function test_the_list_is_newest_first_filtered_and_paginated(): void
    {
        foreach (['a', 'b', 'c'] as $i => $name) {
            $this->travel($i + 1)->minutes();
            $this->invite("{$name}@example.test", ['name' => "Person {$name}"])->assertCreated();
        }

        $this->delegate('GET', '/invitations?per_page=2')->assertJsonPath('data.0.email', 'c@example.test')->assertJsonCount(2, 'data')->assertJsonPath('meta.pagination.total', 3);
        $this->delegate('GET', '/invitations?search=Person b')->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'b@example.test');
        $this->delegate('GET', '/invitations?status=NOPE')->assertStatus(422);
    }

    public function test_creation_is_rate_limited_per_tenant(): void
    {
        config(['invitations.create_per_tenant_per_hour' => 2]);

        $this->invite('one@example.test')->assertCreated();
        $this->invite('two@example.test')->assertCreated();
        $this->invite('three@example.test')->assertStatus(429)->assertJsonPath('error.code', 'INVITATION_RATE_LIMITED')->assertHeader('Retry-After');

        $this->assertSame(2, UserInvitation::query()->count());
    }

    public function test_a_mail_failure_is_recorded_as_a_failed_delivery_with_a_recovery_path(): void
    {
        // A real SMTP transport pointed at a closed port: the genuine failure path, not a mock.
        Mail::swap($this->realMailManager);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 2]]);
        app('mail.manager')->purge('smtp');

        $response = $this->invite('fail@example.test')->assertCreated()->assertJsonPath('data.delivery.status', 'FAILED')->assertJsonPath('data.delivery.error_code', 'MAIL_TRANSPORT_FAILED');
        $this->assertStringNotContainsString('127.0.0.1', $response->getContent());
        $this->assertTrue(AuditLog::query()->where('action', 'invitation.send_failed')->exists());
        $this->assertFalse(AuditLog::query()->where('action', 'invitation.sent')->exists());

        // A failed delivery can be retried at once (no cooldown) and the new send goes out.
        config(['mail.default' => 'array']);
        Mail::fake();
        $this->delegate('POST', '/invitations/'.$response->json('data.id').'/resend')->assertOk()->assertJsonPath('data.delivery.status', 'SENT');
        Mail::assertSent(UserInvitationMail::class);
    }

    public function test_a_queued_mail_for_a_replaced_or_revoked_invitation_sends_nothing(): void
    {
        $id = $this->invite('stale@example.test')->assertCreated()->json('data.id');
        $old = $this->tokenSentTo('stale@example.test');
        $this->travel(2)->minutes();
        $this->delegate('POST', "/invitations/{$id}/resend")->assertOk();
        Mail::fake();

        (new SendUserInvitation($id, $old))->handle(app(AuditService::class));
        Mail::assertNothingSent();

        $this->delegate('POST', "/invitations/{$id}/revoke")->assertOk();
        $fresh = hash('sha256', 'unused');
        (new SendUserInvitation($id, $fresh))->handle(app(AuditService::class));
        Mail::assertNothingSent();
    }

    public function test_the_job_payload_is_encrypted_and_the_row_never_holds_the_token(): void
    {
        $this->assertInstanceOf(ShouldBeEncrypted::class, new SendUserInvitation('id', 'token'));

        Queue::fake();
        $this->invite('queued@example.test')->assertCreated();
        Queue::assertPushed(SendUserInvitation::class, 1);
    }

    public function test_the_direct_creation_path_is_deprecated_and_can_be_switched_off(): void
    {
        $this->delegate('POST', '/users', ['email' => 'direct@example.test', 'name' => 'Direct'])->assertCreated()
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Link');

        config(['invitations.allow_direct_user_creation' => false]);
        $this->delegate('POST', '/users', ['email' => 'direct2@example.test', 'name' => 'Direct'])->assertStatus(410)->assertJsonPath('error.code', 'DIRECT_USER_CREATION_DISABLED');
        $this->assertSame(0, User::query()->where('email', 'direct2@example.test')->count());
    }

    public function test_a_suspended_tenant_or_unsubscribed_product_cannot_invite(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
        $this->invite()->assertStatus(403)->assertJsonPath('error.code', 'TENANT_NOT_ACTIVE');

        $this->tenant->update(['status' => Tenant::STATUS_ACTIVE]);
        $this->tenant->applications()->updateExistingPivot($this->entry->id, ['status' => 'SUSPENDED']);
        $this->invite()->assertStatus(403)->assertJsonPath('error.code', 'APPLICATION_NOT_SUBSCRIBED');
    }
}
