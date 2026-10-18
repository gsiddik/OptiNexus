<?php

namespace Tests\Feature\Governance;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserInvitation;
use App\Models\UserRole;
use App\Services\Commercial\EntitlementService;
use App\Services\Delegation\InvitationException;
use App\Services\Delegation\InvitationService;
use App\Services\Seats\SeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesInvitationFixtures;
use Tests\TestCase;

/**
 * Central seat-limit enforcement in OptiNexus (owner decision 2026-10-18), through the delegation API and the invitation service,
 * against a real PostgreSQL database. Seats are counted per tenant and application from the source rows, bounded by the tenant's
 * effective `user_limit`; a missing or invalid limit never means unlimited; a limit lowered below usage removes nothing but blocks
 * additions. The parallel-request races are in SeatConcurrencyTest, the other doors (operator, first Admin Tenant) in SeatLimitPathsTest.
 */
class SeatLimitApiTest extends TestCase
{
    use CreatesInvitationFixtures, RefreshDatabase;

    private const PASSWORD = 'correct horse battery 42';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInvitationWorld();
        $this->entry->update(['metadata' => ['manifest' => ['entitlement_limit_keys' => ['user_limit']]]]);
    }

    private function limit(string|int $value, string $source = Entitlement::SOURCE_MANUAL_OVERRIDE, ?Application $application = null, ?Tenant $tenant = null, string $status = Entitlement::STATUS_ACTIVE, bool $tenantWide = false): Entitlement
    {
        return Entitlement::create([
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'application_id' => $tenantWide ? null : ($application ?? $this->entry)->id,
            'entitlement_type' => Entitlement::TYPE_LIMIT,
            'entitlement_key' => 'user_limit',
            'value' => (string) $value,
            'status' => $status,
            'source_type' => $source,
            'effective_from' => now()->subMinute(),
        ]);
    }

    /** @return array<string, mixed> */
    private function seats(?Tenant $tenant = null, ?User $actor = null): array
    {
        return $this->delegate('GET', '/seats', [], $actor, tenant: $tenant)->assertOk()->json('data');
    }

    private function addDirect(string $email): TestResponse
    {
        return $this->delegate('POST', '/users', ['email' => $email, 'name' => 'Direct Person', 'initial_password' => self::PASSWORD]);
    }

    private function assertFigures(array $seats, int $used, int $reserved, int|string|null $limit): void
    {
        $this->assertSame($used, $seats['used'], 'used');
        $this->assertSame($reserved, $seats['reserved'], 'reserved');
        $this->assertSame($limit === 'unlimited' ? null : $limit, $seats['limit'], 'limit');
        $this->assertSame($limit === 'unlimited', $seats['unlimited']);
        if (is_int($limit)) {
            $this->assertSame(max(0, $limit - $used - $reserved), $seats['available'], 'available is never negative');
        }
    }

    // ---------------------------------------------------------------- the limit and what it means

    public function test_an_application_without_the_seat_contract_is_not_controlled_and_nothing_is_invented(): void
    {
        $this->entry->update(['metadata' => null]);

        $seats = $this->seats();
        $this->assertSame('NOT_CONTROLLED', $seats['state']);
        $this->assertFalse($seats['controlled']);
        $this->assertNull($seats['limit']);
        $this->assertTrue($seats['can_add']);
        $this->assertSame(1, $seats['used']);

        // A user_limit that exists for it is ignored: the product never declared one, so there is no seat contract to enforce.
        $this->limit(1);
        foreach (range(1, 3) as $i) {
            $this->invite("free{$i}@example.test")->assertCreated();
        }
        $this->assertSame('NOT_CONTROLLED', $this->seats()['state']);
    }

    public function test_a_declared_limit_is_reported_with_used_reserved_and_available(): void
    {
        $this->limit(3);
        $this->invite('a@example.test')->assertCreated();

        $seats = $this->seats();
        $this->assertFigures($seats, 1, 1, 3);
        $this->assertSame('LIMITED', $seats['state']);
        $this->assertSame(1, $seats['available']);
        $this->assertFalse($seats['over_limit']);
        $this->assertTrue($seats['can_add']);
        $this->assertNull($seats['blocked_reason']);
        $this->assertSame(Entitlement::SOURCE_MANUAL_OVERRIDE, $seats['limit_source']);

        // The same figures ride with the user and invitation lists.
        $this->assertSame($seats, $this->delegate('GET', '/users')->json('meta.seats'));
        $this->assertSame($seats, $this->delegate('GET', '/invitations')->json('meta.seats'));
    }

    public function test_no_entitlement_means_nobody_can_be_added_never_unlimited(): void
    {
        $seats = $this->seats();
        $this->assertSame('ENTITLEMENT_MISSING', $seats['state']);
        $this->assertFalse($seats['can_add']);
        $this->assertSame('SEAT_ENTITLEMENT_MISSING', $seats['blocked_reason']);
        $this->assertNull($seats['available']);

        $this->invite('a@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_ENTITLEMENT_MISSING')->assertJsonPath('error.details.recovery', 'SET_ENTITLEMENT');
        $this->addDirect('b@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_ENTITLEMENT_MISSING');
        $this->assertSame(0, UserInvitation::query()->count());
        $this->assertSame(0, User::query()->where('email', 'b@example.test')->count());
        $this->assertSame(1, UserApplicationAccess::query()->count(), 'the existing access stays');

        // A lapsed subscription suspends its entitlements: same outcome.
        $this->limit(5, Entitlement::SOURCE_PLAN, status: Entitlement::STATUS_SUSPENDED);
        $this->limit(5, Entitlement::SOURCE_PLAN, status: Entitlement::STATUS_EXPIRED);
        $this->invite('a@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_ENTITLEMENT_MISSING');

        // A limit that has not started yet or already ended is not in force either.
        $future = $this->limit(5);
        $future->update(['effective_from' => now()->addDay()]);
        $this->invite('a@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_ENTITLEMENT_MISSING');
        $future->update(['effective_from' => now()->subDays(2), 'effective_until' => now()->subDay()]);
        $this->invite('a@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_ENTITLEMENT_MISSING');
    }

    public function test_an_invalid_limit_refuses_additions_and_is_not_read_as_zero_or_unlimited(): void
    {
        foreach (['abc', '-2', '2.5', ''] as $bad) {
            $row = $this->limit($bad);
            $this->assertSame('ENTITLEMENT_INVALID', $this->seats()['state'], "value [{$bad}]");
            $this->invite('a@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_ENTITLEMENT_INVALID');
            $this->addDirect('b@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_ENTITLEMENT_INVALID');
            $row->delete();
        }

        // Two overrides that disagree: which one wins would be arbitrary, so it is invalid, not whichever came first.
        $this->limit(3);
        $this->limit(30);
        $this->assertSame('ENTITLEMENT_INVALID', $this->seats()['state']);
        $this->invite('a@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_ENTITLEMENT_INVALID');
        $this->assertSame(0, UserInvitation::query()->count());
    }

    public function test_an_unreadable_entitlement_source_is_retryable_and_never_unlimited(): void
    {
        $this->limit(10);
        $this->app->instance(SeatService::class, new class(app(EntitlementService::class)) extends SeatService
        {
            protected function resolveLimit(Tenant $tenant, Application $application): array
            {
                throw new \RuntimeException('entitlement source down');
            }
        });

        $this->invite('a@example.test')->assertStatus(503)->assertHeader('Retry-After')->assertJsonPath('error.code', 'SEAT_ENTITLEMENT_UNAVAILABLE');
        $this->addDirect('b@example.test')->assertStatus(503)->assertJsonPath('error.code', 'SEAT_ENTITLEMENT_UNAVAILABLE');
        $this->assertSame(0, UserInvitation::query()->count());
        $this->assertSame('ENTITLEMENT_UNAVAILABLE', $this->seats()['state']);
    }

    public function test_an_explicit_unlimited_allows_additions_and_reports_no_number(): void
    {
        $this->limit('unlimited');
        foreach (range(1, 4) as $i) {
            $this->invite("u{$i}@example.test")->assertCreated();
        }

        $seats = $this->seats();
        $this->assertFigures($seats, 1, 4, 'unlimited');
        $this->assertNull($seats['available']);
        $this->assertFalse($seats['over_limit']);
        $this->assertTrue($seats['can_add']);
    }

    public function test_plan_and_addon_limits_add_up_and_an_override_wins(): void
    {
        $this->limit(2, Entitlement::SOURCE_PLAN, tenantWide: true);
        $this->limit(3, Entitlement::SOURCE_ADDON, tenantWide: true);
        $this->assertFigures($this->seats(), 1, 0, 5);
        $this->assertSame('PLAN', $this->seats()['limit_source']);

        $this->limit(2);
        $this->assertFigures($this->seats(), 1, 0, 2);
    }

    // ---------------------------------------------------------------- reservations

    public function test_pending_invitations_reserve_seats_and_the_last_one_is_refused(): void
    {
        $this->limit(3);
        $this->invite('a@example.test')->assertCreated();
        $this->invite('b@example.test')->assertCreated();
        $this->assertFigures($this->seats(), 1, 2, 3);

        $this->invite('c@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_LIMIT_REACHED')
            ->assertJsonPath('error.details.limit', 3)->assertJsonPath('error.details.used', 1)->assertJsonPath('error.details.reserved', 2)
            ->assertJsonPath('error.details.available', 0)->assertJsonPath('error.details.recovery', 'FREE_SEAT_OR_RAISE_LIMIT');
        $this->assertSame(2, UserInvitation::query()->count(), 'a refused invitation leaves no row');
        $this->assertTrue(AuditLog::query()->where('action', 'seat.denied')->where('tenant_id', $this->tenant->id)->exists());
    }

    public function test_a_duplicate_invitation_and_a_resend_never_reserve_a_second_seat(): void
    {
        $this->limit(3);
        $id = $this->invite('a@example.test')->assertCreated()->json('data.id');

        $this->invite('a@example.test')->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_PENDING');
        $this->travel(2)->minutes();
        $this->delegate('POST', "/invitations/{$id}/resend")->assertOk();

        $this->assertFigures($this->seats(), 1, 1, 3);
        $this->assertSame(1, UserInvitation::query()->count());
    }

    public function test_revoking_or_expiring_an_invitation_releases_its_seat_at_once(): void
    {
        $this->limit(2);
        $id = $this->invite('a@example.test')->assertCreated()->json('data.id');
        $this->invite('b@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_LIMIT_REACHED');

        $this->delegate('POST', "/invitations/{$id}/revoke", ['reason' => 'wrong person'])->assertOk();
        $this->assertFigures($this->seats(), 1, 0, 2);
        $this->invite('b@example.test')->assertCreated();

        // Expiry counts the moment the time is up - before any sweeper stamped the row.
        $this->travel(80)->hours();
        $this->assertSame(UserInvitation::STATUS_PENDING, $this->invitationFor('b@example.test')->status, 'not stamped yet');
        $this->assertFigures($this->seats(), 1, 0, 2);
        $this->invite('c@example.test')->assertCreated();

        $this->artisan('invitations:expire')->assertSuccessful();
        $this->assertFigures($this->seats(), 1, 1, 2);
    }

    public function test_reopening_an_expired_invitation_needs_a_free_seat_again(): void
    {
        $this->limit(2);
        $id = $this->invite('a@example.test')->assertCreated()->json('data.id');
        $this->travel(80)->hours();
        $this->invite('b@example.test')->assertCreated();

        $this->delegate('POST', "/invitations/{$id}/resend")->assertStatus(409)->assertJsonPath('error.code', 'SEAT_LIMIT_REACHED');
        $this->assertSame('EXPIRED', UserInvitation::query()->find($id)->effectiveStatus());

        $this->delegate('POST', '/invitations/'.$this->invitationFor('b@example.test')->id.'/revoke')->assertOk();
        $this->delegate('POST', "/invitations/{$id}/resend")->assertOk();
        $this->assertFigures($this->seats(), 1, 1, 2);
    }

    public function test_accepting_turns_the_reservation_into_usage_without_counting_twice(): void
    {
        $this->limit(2);
        $this->invite('ann@example.test')->assertCreated();
        $this->assertFigures($this->seats(), 1, 1, 2);

        $invitation = $this->invitationFor('ann@example.test');
        app(InvitationService::class)->accept($invitation->id, $invitation->token_hash, ['name' => 'Ann Lee', 'password' => self::PASSWORD], Request::create('/invitations/accept', 'POST'));

        $this->assertFigures($this->seats(), 2, 0, 2);
        $this->assertSame(UserInvitation::STATUS_ACCEPTED, $invitation->refresh()->status);
        $this->invite('late@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_LIMIT_REACHED');
    }

    public function test_a_direct_add_of_someone_who_already_reserves_a_seat_converts_it_instead_of_counting_twice(): void
    {
        $this->limit(2);
        $this->invite('ann@example.test')->assertCreated();
        $this->assertFigures($this->seats(), 1, 1, 2);

        // Full: a stranger is refused, the person who already holds the reservation is not.
        $this->addDirect('stranger@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_LIMIT_REACHED');
        $this->addDirect('ann@example.test')->assertCreated();

        $this->assertFigures($this->seats(), 2, 0, 2);
        $this->assertSame(1, User::query()->where('email', 'ann@example.test')->count());

        // The invitation is still open, but its recipient holds the seat, so it reserves nothing more.
        $this->assertSame(UserInvitation::STATUS_PENDING, $this->invitationFor('ann@example.test')->status);
        $this->assertFigures($this->seats(), 2, 0, 2);
    }

    // ---------------------------------------------------------------- who counts

    public function test_a_person_counts_once_however_many_roles_and_suspended_people_count(): void
    {
        $this->limit(5);
        $second = $this->applicationRole($this->tenant, 'AUDITOR', ['entry-test.journal.view']);
        $person = $this->member(name: 'Many Roles');
        foreach ([$this->clerk, $second] as $role) {
            UserRole::create(['user_id' => $person->id, 'role_id' => $role->id, 'tenant_id' => $this->tenant->id]);
        }
        $this->assertFigures($this->seats(), 2, 0, 5);

        $this->member(name: 'Suspended', status: User::STATUS_SUSPENDED);
        $this->member(name: 'Disabled', status: User::STATUS_DISABLED);
        $suspendedMember = $this->member(name: 'Membership suspended');
        TenantMembership::query()->where('user_id', $suspendedMember->id)->update(['status' => TenantMembership::STATUS_SUSPENDED]);
        $this->assertFigures($this->seats(), 5, 0, 5);
        $this->invite('x@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_LIMIT_REACHED');
    }

    public function test_the_admin_tenant_counts_and_a_revoked_assignment_does_not(): void
    {
        $this->limit(3);
        $this->assertFigures($this->seats(), 1, 0, 3);

        $gone = $this->member(name: 'Gone');
        UserApplicationAccess::query()->where('user_id', $gone->id)->update(['status' => UserApplicationAccess::STATUS_REVOKED]);
        $this->assertFigures($this->seats(), 1, 0, 3);

        $deleted = $this->member(name: 'Deleted identity');
        $deleted->delete();
        $this->assertFigures($this->seats(), 1, 0, 3);
    }

    public function test_service_principals_are_not_people_and_a_human_counts_whatever_their_address_looks_like(): void
    {
        $this->limit(3);
        // The product's service account (issued in the world fixture) is in a separate registry: it never occupies a seat.
        $this->assertSame(1, ServiceAccount::query()->where('application_id', $this->entry->id)->count());
        $this->assertFigures($this->seats(), 1, 0, 3);

        // A person whose e-mail looks like a bot is still a person: the registry decides, not the address.
        $this->member(name: 'Looks like a bot', user: ['email' => 'svc-integration@service.example']);
        $this->member(name: 'No-reply', user: ['email' => 'noreply@corp.example']);
        $this->assertFigures($this->seats(), 3, 0, 3);

        $this->assertFalse(Schema::hasColumn('user_application_access', 'service_account_id'));
    }

    public function test_the_same_person_in_two_tenants_or_two_products_holds_separate_seats(): void
    {
        $this->limit(2);
        $person = $this->member(name: 'Everywhere');

        $other = $this->makeTenant(null, ['name' => 'Other Co']);
        $other->applications()->attach($this->entry->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $otherAdmin = $this->member(admin: true, name: 'Other admin', tenant: $other);
        $this->limit(2, tenant: $other);
        TenantMembership::create(['user_id' => $person->id, 'tenant_id' => $other->id, 'status' => 'ACTIVE', 'is_tenant_admin' => false]);
        UserApplicationAccess::create(['user_id' => $person->id, 'tenant_id' => $other->id, 'application_id' => $this->entry->id, 'status' => 'ACTIVE']);

        $this->assertFigures($this->seats(), 2, 0, 2);
        $this->assertFigures($this->seats($other, $otherAdmin), 2, 0, 2);

        // Access to another product of the same tenant is another budget.
        $this->tenant->applications()->attach($this->otherApp->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        UserApplicationAccess::create(['user_id' => $person->id, 'tenant_id' => $this->tenant->id, 'application_id' => $this->otherApp->id, 'status' => 'ACTIVE']);
        $this->assertFigures($this->seats(), 2, 0, 2);
        $this->assertSame(1, app(SeatService::class)->usage($this->tenant->id, $this->otherApp->id)[0]);
        $this->assertNotNull($otherAdmin);
    }

    public function test_revoking_the_product_releases_the_seat_and_keeps_the_identity_and_membership(): void
    {
        $this->limit(2);
        $person = $this->member(name: 'Leaving');
        $this->assertFigures($this->seats(), 2, 0, 2);
        $this->addDirect('blocked@example.test')->assertStatus(409);

        $this->delegate('DELETE', "/users/{$person->id}")->assertOk();

        $this->assertFigures($this->seats(), 1, 0, 2);
        $this->assertNotNull(User::query()->find($person->id));
        $this->assertTrue(TenantMembership::query()->where('user_id', $person->id)->where('tenant_id', $this->tenant->id)->exists());
        $this->addDirect('blocked@example.test')->assertCreated();
    }

    // ---------------------------------------------------------------- limit lowered below usage

    public function test_a_limit_lowered_below_usage_keeps_access_marks_over_limit_and_blocks_additions_until_capacity_suffices(): void
    {
        $limit = $this->limit(5);
        $people = collect(range(1, 3))->map(fn (int $i) => $this->member(name: "P{$i}"));
        $this->assertFigures($this->seats(), 4, 0, 5);

        $limit->update(['value' => '2']);

        $seats = $this->seats();
        $this->assertTrue($seats['over_limit']);
        $this->assertSame(2, $seats['over_by']);
        $this->assertSame(0, $seats['available'], 'never negative');
        $this->assertFalse($seats['can_add']);
        $this->assertSame('SEAT_OVER_LIMIT', $seats['blocked_reason']);
        $this->assertSame(4, UserApplicationAccess::query()->where('status', 'ACTIVE')->count(), 'nothing is revoked automatically');

        $this->invite('a@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_OVER_LIMIT')->assertJsonPath('error.details.over_limit', true);
        $this->addDirect('b@example.test')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_OVER_LIMIT');

        // Reducing usage is always allowed; additions stay blocked until there is room.
        $this->delegate('DELETE', '/users/'.$people[0]->id)->assertOk();
        $this->assertSame('SEAT_OVER_LIMIT', $this->seats()['blocked_reason']);
        $this->delegate('DELETE', '/users/'.$people[1]->id)->assertOk();
        $this->assertSame('SEAT_LIMIT_REACHED', $this->seats()['blocked_reason']);
        $this->delegate('DELETE', '/users/'.$people[2]->id)->assertOk();
        $this->invite('a@example.test')->assertCreated();
    }

    public function test_an_invitation_that_lost_its_seat_is_kept_but_cannot_be_accepted_and_nothing_is_granted(): void
    {
        $limit = $this->limit(3);
        $this->invite('a@example.test')->assertCreated();
        $this->invite('b@example.test')->assertCreated();
        $limit->update(['value' => '2']);

        $this->assertFigures($this->seats(), 1, 2, 2);
        $this->assertTrue($this->seats()['over_limit']);
        $this->assertSame(2, UserInvitation::query()->where('status', 'PENDING')->count(), 'no invitation is cancelled automatically');

        // The first acceptance still fits (used + 1 <= limit); the second would exceed the limit and is held back with a clear reason.
        $this->accept('a@example.test');
        $this->assertFigures($this->seats(), 2, 1, 2);

        $b = $this->invitationFor('b@example.test');
        try {
            $this->accept('b@example.test');
            $this->fail('the acceptance must be refused');
        } catch (InvitationException $e) {
            $this->assertSame(InvitationException::NO_SEAT, $e->state);
        }

        $this->assertSame(0, User::query()->where('email', 'b@example.test')->count(), 'no identity from a refused acceptance');
        $this->assertSame(UserInvitation::STATUS_PENDING, $b->refresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'invitation.accept_denied')->where('resource_id', $b->id)->get()->contains(fn ($l) => ($l->metadata['seat_code'] ?? null) === 'SEAT_OVER_LIMIT'));

        // Recovery: raise the limit, then the same invitation works.
        $limit->update(['value' => '3']);
        $this->accept('b@example.test');
        $this->assertFigures($this->seats(), 3, 0, 3);
    }

    public function test_the_invitee_sees_a_clear_page_when_the_seat_is_gone_and_no_password_is_taken(): void
    {
        $limit = $this->limit(2);
        $this->invite('ann@example.test')->assertCreated();
        $token = $this->tokenSentTo('ann@example.test');
        $limit->delete();

        $cookie = (string) $this->get("/invitations/{$token}")->getCookie(config('session.cookie'), false)->getValue();
        $page = $this->withCookie(config('session.cookie'), $cookie)->get('/invitations/accept')->assertStatus(409);
        $page->assertSee('There is no free seat for this access');
        $page->assertDontSee('name="password"', false);
        $page->assertDontSee($token);

        $indonesian = $this->withCookie(config('session.cookie'), $cookie)->get('/invitations/accept?lang=id')->assertStatus(409);
        $indonesian->assertSee('Tidak ada kursi kosong untuk akses ini');

        $posted = $this->withCookie(config('session.cookie'), $cookie)->post('/invitations/accept', ['name' => 'Ann', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
        $posted->assertStatus(409);
        $this->assertSame(0, User::query()->where('email', 'ann@example.test')->count());
    }

    public function test_a_refused_acceptance_rolls_everything_back(): void
    {
        $limit = $this->limit(2);
        $this->invite('ann@example.test')->assertCreated();
        $limit->update(['value' => '1']);
        $counts = fn () => [User::count(), TenantMembership::count(), UserApplicationAccess::count(), UserRole::count()];
        $before = $counts();

        $this->expectException(InvitationException::class);
        try {
            $this->accept('ann@example.test');
        } finally {
            $this->assertSame($before, $counts(), 'no half identity, membership, access or role');
        }
    }

    // ---------------------------------------------------------------- isolation

    public function test_a_tenant_only_ever_sees_and_spends_its_own_seats(): void
    {
        $this->limit(2);
        $other = $this->makeTenant(null, ['name' => 'Other Co']);
        $other->applications()->attach($this->entry->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $otherAdmin = $this->member(admin: true, name: 'Other admin', tenant: $other);
        $this->limit(50, tenant: $other);
        foreach (range(1, 5) as $i) {
            $this->member(name: "Other {$i}", tenant: $other);
        }

        $this->assertFigures($this->seats(), 1, 0, 2);
        $this->assertFigures($this->seats($other, $otherAdmin), 6, 0, 50);

        // The acting user must belong to the tenant in the path: a foreign admin cannot read or spend this tenant's seats.
        $this->delegate('GET', '/seats', [], $otherAdmin)->assertStatus(403)->assertJsonPath('error.code', 'ACTOR_NOT_TENANT_ADMIN');
        $this->invite('x@example.test', [], $otherAdmin)->assertStatus(403);

        // A refusal of this tenant carries only this tenant's own numbers.
        $this->invite('a@example.test')->assertCreated();
        $refusal = $this->invite('b@example.test')->assertStatus(409);
        $this->assertStringNotContainsString((string) $other->id, $refusal->getContent());
        $this->assertSame(1, $refusal->json('error.details.used'));
    }

    public function test_audit_trail_records_the_denial_without_credentials_or_tokens(): void
    {
        $this->limit(1);
        $this->invite('a@example.test')->assertStatus(409);
        $this->addDirect('b@example.test')->assertStatus(409);

        $denied = AuditLog::query()->where('action', 'seat.denied')->get();
        $this->assertCount(2, $denied);
        $this->assertSame('SEAT_LIMIT_REACHED', $denied[0]->new_value['code']);
        $this->assertSame($this->entry->id, $denied[0]->application_id);
        $this->assertSame($this->admin->id, $denied[0]->metadata['acting_user_id']);
        $this->assertStringNotContainsString(self::PASSWORD, AuditLog::query()->get()->toJson());
    }

    private function accept(string $email): void
    {
        $invitation = $this->invitationFor($email);
        app(InvitationService::class)->accept($invitation->id, $invitation->token_hash, ['name' => 'Accepted', 'password' => self::PASSWORD], Request::create('/invitations/accept', 'POST'));
    }
}
