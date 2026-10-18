<?php

namespace Tests\Feature\Governance;

use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInvitationFixtures;
use Tests\TestCase;

/**
 * An account an operator created through the first Admin Tenant flow finishes through that flow only (temporary password + operator
 * verification). The two other doors into an existing identity - the delegation API and the invitation link - must not complete,
 * re-use or take it over: that would skip the gate and replace the password the operator handed over.
 */
class ManagedActivationDoorsTest extends TestCase
{
    use CreatesInvitationFixtures, RefreshDatabase;

    private User $pending;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInvitationWorld();

        $this->pending = User::factory()->unverified()->create(['name' => 'Pending Admin', 'email' => 'pending.admin@example.test', 'status' => User::STATUS_INVITED]);
        $this->pending->forceFill(['activation_started_at' => now(), 'password_change_required' => true, 'temporary_password_expires_at' => now()->addDay(), 'temporary_password_issued_at' => now()])->save();
        TenantMembership::create(['user_id' => $this->pending->id, 'tenant_id' => $this->tenant->id, 'status' => TenantMembership::STATUS_ACTIVE, 'is_tenant_admin' => true]);
        UserApplicationAccess::create(['user_id' => $this->pending->id, 'tenant_id' => $this->tenant->id, 'application_id' => $this->entry->id, 'status' => UserApplicationAccess::STATUS_ACTIVE]);
    }

    public function test_the_delegation_api_does_not_re_use_an_account_in_activation(): void
    {
        $hash = $this->pending->fresh()->password;

        $this->delegate('POST', '/users', ['email' => 'pending.admin@example.test', 'name' => 'Pending Admin', 'initial_password' => 'Takeover!Password12'])
            ->assertStatus(409)->assertJsonPath('error.code', 'IDENTITY_NOT_LINKABLE');

        $fresh = $this->pending->fresh();
        $this->assertSame($hash, $fresh->password);
        $this->assertSame(User::STATUS_INVITED, $fresh->status);
    }

    public function test_an_invitation_cannot_complete_an_account_in_activation(): void
    {
        // The invitation is for an application the pending account does not hold yet, so the invite itself is allowed; accepting it must not be.
        UserApplicationAccess::query()->where('user_id', $this->pending->id)->delete();
        $this->invite('pending.admin@example.test')->assertCreated();
        $token = $this->tokenSentTo('pending.admin@example.test');
        $hash = $this->pending->fresh()->password;

        $response = $this->get("/invitations/{$token}")->assertRedirect('/invitations/accept');
        $cookie = (string) $response->getCookie(config('session.cookie'), false)->getValue();

        $page = $this->withCookie(config('session.cookie'), $cookie)->post('/invitations/accept', ['name' => 'Pending Admin', 'password' => 'Takeover!Password12', 'password_confirmation' => 'Takeover!Password12']);

        $page->assertStatus(409)->assertSee('cannot be used for this e-mail address');
        $fresh = $this->pending->fresh();
        $this->assertSame($hash, $fresh->password, 'the password the operator handed over is not replaced');
        $this->assertSame(User::STATUS_INVITED, $fresh->status);
        $this->assertNull($fresh->email_verified_at, 'an invitation does not verify the address of an account in activation');
    }
}
