<?php

namespace Tests\Feature\Governance;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserInvitation;
use App\Models\UserRole;
use App\Services\Oidc\OidcTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesInvitationFixtures;
use Tests\TestCase;

/**
 * Onboarding by invitation, the invitee's half (browser pages on Nexus). Real pages, real sessions, real database: the e-mailed link
 * validates and hands over to a clean URL, acceptance re-validates everything under a row lock, and nothing is granted otherwise.
 */
class InvitationAcceptanceTest extends TestCase
{
    use CreatesInvitationFixtures, RefreshDatabase;

    private const PASSWORD = 'correct horse battery 42';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInvitationWorld();
    }

    /** Invites, opens the e-mailed link and returns the visitor's session cookie. */
    private function openLink(string $email, array $extra = []): string
    {
        $this->invite($email, $extra)->assertCreated();

        return $this->follow($this->tokenSentTo($email));
    }

    private function follow(string $token): string
    {
        $response = $this->get("/invitations/{$token}");
        $response->assertRedirect('/invitations/accept');
        $this->assertStringNotContainsString($token, (string) $response->headers->get('Location'), 'the token must leave the URL at once');

        return (string) $response->getCookie(config('session.cookie'), false)->getValue();
    }

    private function page(string $cookie, string $query = ''): TestResponse
    {
        return $this->withCookie(config('session.cookie'), $cookie)->get('/invitations/accept'.$query);
    }

    private function submit(string $cookie, array $data): TestResponse
    {
        return $this->withCookie(config('session.cookie'), $cookie)->post('/invitations/accept', $data);
    }

    private function newPerson(array $overrides = []): array
    {
        return $overrides + ['name' => 'Ann Lee', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
    }

    public function test_opening_the_link_consumes_nothing_and_the_page_is_safe_to_cache_nowhere(): void
    {
        $cookie = $this->openLink('ann@example.test');

        // A mail scanner (or a second click) opening the link again does not burn it.
        $this->follow($this->tokenSentTo('ann@example.test'));
        $this->assertTrue($this->invitationFor('ann@example.test')->isOpen());

        $page = $this->page($cookie)->assertOk()->assertSee('Accept your invitation')->assertSee('Entry Test')->assertSee('Acme Test')
            ->assertSee('ann@example.test')->assertDontSee('current_password', false);
        $page->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $page->headers->get('Content-Security-Policy'));
        $this->assertSame(0, User::query()->where('email', 'ann@example.test')->count());
    }

    public function test_a_new_person_sets_a_password_proves_the_mailbox_and_gets_exactly_the_invited_access(): void
    {
        $cookie = $this->openLink('ann@example.test');

        $done = $this->submit($cookie, $this->newPerson())->assertOk()->assertSee('Your access is ready')->assertSee('https://entry.test');

        $user = User::query()->where('email', 'ann@example.test')->firstOrFail();
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertNotNull($user->email_verified_at, 'possession of the mailbox is the proof');
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertSame('Ann Lee', $user->name);

        $this->assertTrue(TenantMembership::query()->where('user_id', $user->id)->where('tenant_id', $this->tenant->id)->where('status', 'ACTIVE')->where('is_tenant_admin', false)->exists());
        $this->assertTrue(UserApplicationAccess::query()->where('user_id', $user->id)->where('application_id', $this->entry->id)->where('status', 'ACTIVE')->exists());
        $this->assertSame([$this->clerk->id], UserRole::query()->where('user_id', $user->id)->pluck('role_id')->all());
        $this->assertSame(1, TenantMembership::query()->where('user_id', $user->id)->count(), 'no second tenant');

        $invitation = $this->invitationFor('ann@example.test');
        $this->assertSame(UserInvitation::STATUS_ACCEPTED, $invitation->status);
        $this->assertSame($user->id, $invitation->accepted_user_id);
        $this->assertGuest();

        foreach (['invitation.accepted', 'invitation.email_verified'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->where('actor_user_id', $user->id)->exists(), $action);
        }
        $this->assertStringNotContainsString(self::PASSWORD, AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString($this->tokenSentTo('ann@example.test'), AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString(self::PASSWORD, $done->getContent());
    }

    public function test_the_accepted_person_is_seen_by_the_product_as_a_verified_member(): void
    {
        $cookie = $this->openLink('ann@example.test');
        $this->submit($cookie, $this->newPerson())->assertOk();
        $user = User::query()->where('email', 'ann@example.test')->firstOrFail();

        // The same view the product's delegated user list uses, and the claim an OIDC product reads.
        $row = collect($this->delegate('GET', '/users')->assertOk()->json('data'))->firstWhere('email', 'ann@example.test');
        $this->assertSame($user->id, $row['id']);
        $this->assertSame('ACTIVE', $row['application_access']);
        $this->assertSame(['CLERK'], collect($row['roles'])->pluck('code')->all());
        $this->assertTrue(app(OidcTokenService::class)->userClaims($user, $this->tenant, ['openid', 'email', 'profile'])['email_verified'] ?? false);
    }

    public function test_a_used_invitation_cannot_be_replayed(): void
    {
        $this->invite('ann@example.test')->assertCreated();
        $link = $this->tokenSentTo('ann@example.test');
        $invitation = $this->invitationFor('ann@example.test');
        $first = $this->follow($link);

        $this->submit($first, $this->newPerson())->assertOk();
        $before = [User::query()->count(), UserRole::query()->count(), TenantMembership::query()->count()];

        // A second browser that had opened the same link before the first one finished (its own session holds the same binding).
        $this->withSession(['invitation.id' => $invitation->id, 'invitation.hash' => $invitation->token_hash])
            ->post('/invitations/accept', $this->newPerson(['password' => 'a different password 99', 'password_confirmation' => 'a different password 99']))
            ->assertStatus(410)->assertSee('already used');
        // The link opened again says the invitation was used and offers no form.
        $this->page($this->follow($link))->assertStatus(410)->assertSee('already used')->assertDontSee('name="password"', false);

        $this->assertSame($before, [User::query()->count(), UserRole::query()->count(), TenantMembership::query()->count()]);
        $this->assertTrue(Hash::check(self::PASSWORD, User::query()->where('email', 'ann@example.test')->firstOrFail()->password));
    }

    public function test_an_expired_invitation_grants_nothing_and_a_resent_one_works(): void
    {
        $cookie = $this->openLink('late@example.test');
        $id = $this->invitationFor('late@example.test')->id;

        $this->travel(73)->hours();
        $this->page($cookie)->assertStatus(410)->assertSee('expired')->assertSee('send the invitation again');
        $this->submit($cookie, $this->newPerson())->assertStatus(410);
        $this->assertSame(0, User::query()->where('email', 'late@example.test')->count());

        $this->delegate('POST', "/invitations/{$id}/resend")->assertOk();
        $fresh = $this->follow($this->tokenSentTo('late@example.test'));
        $this->submit($fresh, $this->newPerson())->assertOk()->assertSee('Your access is ready');

        // The old, expired session can still not be used.
        $this->submit($cookie, $this->newPerson())->assertStatus(404);
    }

    public function test_a_revoked_invitation_cannot_be_accepted(): void
    {
        $cookie = $this->openLink('rev@example.test');
        $id = $this->invitationFor('rev@example.test')->id;
        $this->delegate('POST', "/invitations/{$id}/revoke", ['reason' => 'Mistake'])->assertOk();

        $this->page($cookie)->assertStatus(410)->assertSee('withdrawn');
        $this->submit($cookie, $this->newPerson())->assertStatus(410);
        $this->assertSame(0, User::query()->where('email', 'rev@example.test')->count());
        $this->assertSame(1, TenantMembership::query()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_a_replaced_link_is_dead_even_for_a_visitor_already_on_the_page(): void
    {
        $cookie = $this->openLink('swap@example.test');
        $old = $this->tokenSentTo('swap@example.test');
        $id = $this->invitationFor('swap@example.test')->id;
        $this->travel(2)->minutes();
        $this->delegate('POST', "/invitations/{$id}/resend")->assertOk();

        $this->get("/invitations/{$old}")->assertStatus(404)->assertSee('not valid');
        $this->submit($cookie, $this->newPerson())->assertStatus(404);
        $this->assertSame(0, User::query()->where('email', 'swap@example.test')->count());
    }

    public function test_unknown_malformed_or_foreign_tokens_all_look_the_same(): void
    {
        foreach ([str_repeat('a', 64), 'short', str_repeat('G', 64), hash('sha256', 'x')] as $token) {
            $this->get("/invitations/{$token}")->assertStatus(404)->assertSee('not valid');
        }
        $this->page('nothing')->assertStatus(404);
        $this->submit('nothing', $this->newPerson())->assertStatus(404);
    }

    public function test_a_session_cannot_be_pointed_at_another_invitation(): void
    {
        $this->invite('one@example.test')->assertCreated();
        $this->invite('two@example.test')->assertCreated();
        $one = $this->invitationFor('one@example.test');
        $two = $this->invitationFor('two@example.test');

        // The id of one with the hash of the other: the session binding refuses it.
        $this->withSession(['invitation.id' => $one->id, 'invitation.hash' => $two->token_hash])->get('/invitations/accept')->assertStatus(404);
        $this->withSession(['invitation.id' => $one->id, 'invitation.hash' => $two->token_hash])->post('/invitations/accept', $this->newPerson())->assertStatus(404);
        $this->assertSame(0, User::query()->whereIn('email', ['one@example.test', 'two@example.test'])->count());
    }

    public function test_password_rules_are_enforced_and_nothing_is_created_on_a_bad_form(): void
    {
        $cookie = $this->openLink('ann@example.test');

        $this->submit($cookie, $this->newPerson(['password' => 'short', 'password_confirmation' => 'short']))->assertStatus(422)->assertSee('at least 12 characters');
        $this->submit($cookie, $this->newPerson(['password_confirmation' => 'something else entirely']))->assertStatus(422)->assertSee('not the same');
        $this->submit($cookie, $this->newPerson(['name' => '  ']))->assertStatus(422)->assertSee('Enter your name');

        $this->assertSame(0, User::query()->where('email', 'ann@example.test')->count());
        $this->assertTrue($this->invitationFor('ann@example.test')->isOpen());
        $this->submit($cookie, $this->newPerson())->assertOk();
    }

    public function test_the_page_follows_the_invited_language_and_can_be_switched(): void
    {
        $cookie = $this->openLink('budi@example.test', ['locale' => 'id', 'name' => 'Budi']);

        $this->page($cookie)->assertSee('Terima undangan Anda')->assertSee('Buat kata sandi');
        $this->page($cookie, '?lang=en')->assertSee('Accept your invitation');
        $this->submit($cookie, ['name' => 'Budi', 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422)->assertSee('at least 12 characters');
    }

    public function test_an_existing_identity_is_never_linked_by_e_mail_alone(): void
    {
        $existing = User::factory()->create(['email' => 'carol@example.test', 'name' => 'Carol', 'status' => User::STATUS_ACTIVE, 'password' => 'carols own password 77', 'email_verified_at' => null]);
        $cookie = $this->openLink('carol@example.test');

        $this->page($cookie)->assertOk()->assertSee('already has an OptiNexus account')->assertSee('name="current_password"', false)->assertDontSee('name="password"', false);

        // Without the password, or with a wrong one, nothing is granted.
        $this->submit($cookie, [])->assertStatus(422)->assertSee('Enter your current password');
        $this->submit($cookie, ['current_password' => 'not carols password'])->assertStatus(422)->assertSee('not correct');
        $this->assertFalse(TenantMembership::query()->where('user_id', $existing->id)->exists());
        $this->assertNull($existing->fresh()->email_verified_at);

        // With the right one: linked, nothing else changes.
        $this->submit($cookie, ['current_password' => 'carols own password 77'])->assertOk()->assertSee('Your access is ready');
        $existing->refresh();
        $this->assertTrue(Hash::check('carols own password 77', $existing->password), 'the password is not changed');
        $this->assertSame('Carol', $existing->name);
        $this->assertNotNull($existing->email_verified_at);
        $this->assertTrue(UserApplicationAccess::query()->where('user_id', $existing->id)->where('application_id', $this->entry->id)->exists());
        $this->assertSame(1, User::query()->where('email', 'carol@example.test')->count());
    }

    public function test_repeated_wrong_passwords_lock_the_invitation_for_a_while(): void
    {
        User::factory()->create(['email' => 'dan@example.test', 'status' => User::STATUS_ACTIVE, 'password' => 'dans own password 55']);
        $cookie = $this->openLink('dan@example.test');
        config(['invitations.confirm_max_attempts' => 3]);

        foreach (range(1, 3) as $_) {
            $this->submit($cookie, ['current_password' => 'wrong wrong wrong'])->assertStatus(422);
        }
        $locked = $this->submit($cookie, ['current_password' => 'dans own password 55'])->assertStatus(429)->assertSee('Too many attempts');
        $this->assertNotNull($locked->headers->get('Retry-After'));
        $this->assertSame(0, UserApplicationAccess::query()->whereIn('user_id', User::query()->where('email', 'dan@example.test')->pluck('id'))->count());
        $this->assertTrue(AuditLog::query()->where('action', 'invitation.accept_denied')->where('metadata->reason', 'locked')->exists());

        RateLimiter::clear('invitation:confirm:'.$this->invitationFor('dan@example.test')->id);
        $this->submit($cookie, ['current_password' => 'dans own password 55'])->assertOk();
    }

    public function test_identities_that_cannot_receive_the_access_are_refused_without_a_grant(): void
    {
        $operatorRole = Role::query()->where('code', 'PLATFORM_SUPERADMIN')->firstOrFail();
        $operator = User::factory()->create(['email' => 'op@example.test', 'status' => User::STATUS_ACTIVE, 'password' => 'operators password 11']);
        $operator->userRoles()->create(['role_id' => $operatorRole->id, 'tenant_id' => null]);
        $disabled = User::factory()->create(['email' => 'off@example.test', 'status' => User::STATUS_DISABLED, 'password' => 'disabled password 11']);
        $suspended = User::factory()->create(['email' => 'sus@example.test', 'status' => User::STATUS_SUSPENDED, 'password' => 'suspended password 11']);
        $deleted = User::factory()->create(['email' => 'gone@example.test', 'status' => User::STATUS_ACTIVE, 'password' => 'deleted password 11']);
        $deleted->delete();
        $muted = User::factory()->create(['email' => 'muted@example.test', 'status' => User::STATUS_ACTIVE, 'password' => 'muted password 1111']);
        TenantMembership::create(['user_id' => $muted->id, 'tenant_id' => $this->tenant->id, 'status' => TenantMembership::STATUS_SUSPENDED, 'is_tenant_admin' => false]);
        $shell = User::factory()->create(['email' => 'shell@example.test', 'status' => User::STATUS_INVITED, 'password' => null]);

        foreach (['op' => 'operators password 11', 'off' => 'disabled password 11', 'sus' => 'suspended password 11', 'gone' => 'deleted password 11', 'muted' => 'muted password 1111'] as $local => $password) {
            $cookie = $this->openLink("{$local}@example.test");
            $this->submit($cookie, ['current_password' => $password])->assertStatus(409)->assertSee('cannot be used for this e-mail');
        }
        // An INVITED shell of another tenant (no membership here) cannot be completed through this tenant's invitation.
        $this->submit($this->openLink('shell@example.test'), $this->newPerson())->assertStatus(409);

        foreach ([$operator, $disabled, $suspended, $shell] as $user) {
            $this->assertFalse(UserApplicationAccess::query()->where('user_id', $user->id)->exists(), $user->email);
        }
        $this->assertSame(User::STATUS_INVITED, $shell->fresh()->status);
        $this->assertNull($shell->fresh()->password);
        $this->assertSame(TenantMembership::STATUS_SUSPENDED, $muted->fresh()->tenantMemberships()->first()->status);
        $this->assertSame(0, UserRole::query()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_a_shell_left_by_the_deprecated_direct_path_is_completed_by_its_own_tenant(): void
    {
        $this->delegate('POST', '/users', ['email' => 'legacy@example.test', 'name' => 'Legacy'])->assertCreated();
        $shell = User::query()->where('email', 'legacy@example.test')->firstOrFail();
        $this->assertSame(User::STATUS_INVITED, $shell->status);
        // It is a member already, so the invitation is refused for being redundant at creation... unless access is missing: remove it.
        UserApplicationAccess::query()->where('user_id', $shell->id)->delete();

        $cookie = $this->openLink('legacy@example.test');
        $this->page($cookie)->assertSee('name="password"', false);
        $this->submit($cookie, $this->newPerson(['name' => 'Legacy Person']))->assertOk();

        $shell->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $shell->status);
        $this->assertTrue(Hash::check(self::PASSWORD, $shell->password));
        $this->assertNotNull($shell->email_verified_at);
        $this->assertSame(1, User::query()->where('email', 'legacy@example.test')->count());
    }

    public function test_a_person_of_another_tenant_keeps_that_membership_and_gains_only_this_one(): void
    {
        $other = $this->makeTenant();
        $other->applications()->attach($this->entry->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $person = $this->member(tenant: $other, name: 'Two tenants', user: ['email' => 'two@example.test', 'password' => 'two tenant password 9']);
        $keep = $this->applicationRole($other, 'KEEP', ['entry-test.journal.view']);
        UserRole::create(['user_id' => $person->id, 'role_id' => $keep->id, 'tenant_id' => $other->id]);

        $cookie = $this->openLink('two@example.test');
        $this->submit($cookie, ['current_password' => 'two tenant password 9'])->assertOk();

        $this->assertEqualsCanonicalizing([$other->id, $this->tenant->id], TenantMembership::query()->where('user_id', $person->id)->pluck('tenant_id')->all());
        $this->assertEqualsCanonicalizing([$keep->id, $this->clerk->id], UserRole::query()->where('user_id', $person->id)->pluck('role_id')->all());
        $this->assertSame($this->tenant->id, UserRole::query()->where('role_id', $this->clerk->id)->value('tenant_id'));
        $this->assertSame($other->id, UserRole::query()->where('role_id', $keep->id)->value('tenant_id'), 'the other tenant role stays in the other tenant');
    }

    public function test_everything_is_checked_again_at_acceptance(): void
    {
        // The inviter lost the Admin Tenant flag.
        $cookie = $this->openLink('a1@example.test');
        TenantMembership::query()->where('user_id', $this->admin->id)->update(['is_tenant_admin' => false]);
        $this->submit($cookie, $this->newPerson())->assertStatus(409)->assertSee('can no longer be used');
        TenantMembership::query()->where('user_id', $this->admin->id)->update(['is_tenant_admin' => true]);

        // The inviter's access to the product was revoked.
        UserApplicationAccess::query()->where('user_id', $this->admin->id)->update(['status' => 'REVOKED']);
        $this->submit($cookie, $this->newPerson())->assertStatus(409);
        UserApplicationAccess::query()->where('user_id', $this->admin->id)->update(['status' => 'ACTIVE']);

        // The inviter is suspended.
        $this->admin->update(['status' => User::STATUS_SUSPENDED]);
        $this->submit($cookie, $this->newPerson())->assertStatus(409);
        $this->admin->update(['status' => User::STATUS_ACTIVE]);

        // The tenant is suspended / the product is no longer subscribed.
        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
        $this->submit($cookie, $this->newPerson())->assertStatus(409);
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVE]);
        $this->tenant->applications()->updateExistingPivot($this->entry->id, ['status' => 'SUSPENDED']);
        $this->submit($cookie, $this->newPerson())->assertStatus(409);
        $this->tenant->applications()->updateExistingPivot($this->entry->id, ['status' => 'ACTIVE']);

        $this->assertSame(0, User::query()->where('email', 'a1@example.test')->count());
        $this->assertTrue($this->invitationFor('a1@example.test')->isOpen(), 'a refused acceptance does not burn the invitation');

        // Once everything holds again, the same invitation works.
        $this->submit($cookie, $this->newPerson())->assertOk();
    }

    public function test_a_role_that_stopped_being_assignable_fails_the_acceptance_and_nothing_is_dropped_silently(): void
    {
        $cookie = $this->openLink('role@example.test', ['role_ids' => [$this->clerk->id]]);

        // The permission is no longer delegable.
        $this->perm['view']->update(['delegable' => false]);
        $this->submit($cookie, $this->newPerson())->assertStatus(409)->assertSee('invited access changed');
        $this->assertSame(0, User::query()->where('email', 'role@example.test')->count());
        $this->assertSame(0, UserRole::query()->where('tenant_id', $this->tenant->id)->count());

        // The role was removed altogether.
        $this->perm['view']->update(['delegable' => true]);
        $this->clerk->delete();
        $this->submit($cookie, $this->newPerson())->assertStatus(409);
        $this->assertSame(0, User::query()->where('email', 'role@example.test')->count());
        $this->assertTrue($this->invitationFor('role@example.test')->isOpen());

        // Resending is refused too until the roles are valid again: the inviter must invite afresh.
        $id = $this->invitationFor('role@example.test')->id;
        $this->travel(2)->minutes();
        $this->delegate('POST', "/invitations/{$id}/resend")->assertStatus(422)->assertJsonPath('error.code', 'ROLE_NOT_ASSIGNABLE');
    }

    public function test_the_invitation_cannot_grant_more_than_it_names(): void
    {
        $extra = $this->applicationRole($this->tenant, 'EXTRA', ['entry-test.journal.post']);
        $cookie = $this->openLink('only@example.test', ['role_ids' => [$this->clerk->id]]);

        // Tampering with the form cannot add roles, a tenant or admin rights.
        $this->submit($cookie, $this->newPerson(['role_ids' => [$extra->id], 'is_tenant_admin' => true, 'tenant_id' => (string) Str::uuid(), 'application_id' => $this->otherApp->id]))->assertOk();

        $user = User::query()->where('email', 'only@example.test')->firstOrFail();
        $this->assertSame([$this->clerk->id], UserRole::query()->where('user_id', $user->id)->pluck('role_id')->all());
        $this->assertFalse((bool) TenantMembership::query()->where('user_id', $user->id)->value('is_tenant_admin'));
        $this->assertSame([$this->entry->id], UserApplicationAccess::query()->where('user_id', $user->id)->pluck('application_id')->all());
    }

    public function test_an_invitation_without_roles_grants_access_only(): void
    {
        $this->invite('plain@example.test', ['role_ids' => []])->assertCreated();
        $cookie = $this->follow($this->tokenSentTo('plain@example.test'));
        $this->submit($cookie, $this->newPerson())->assertOk();

        $user = User::query()->where('email', 'plain@example.test')->firstOrFail();
        $this->assertSame(0, UserRole::query()->where('user_id', $user->id)->count());
        $this->assertTrue(UserApplicationAccess::query()->where('user_id', $user->id)->exists());
    }

    public function test_the_acceptance_form_is_throttled(): void
    {
        $cookie = $this->openLink('ann@example.test');

        for ($i = 0; $i < 10; $i++) {
            $this->submit($cookie, $this->newPerson(['password' => 'short', 'password_confirmation' => 'short']))->assertStatus(422);
        }
        $this->submit($cookie, $this->newPerson())->assertStatus(429);
        $this->assertSame(0, User::query()->where('email', 'ann@example.test')->count());
    }
}
