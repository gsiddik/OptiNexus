<?php

namespace Tests\Feature\Governance;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserInvitation;
use App\Services\Seats\SeatException;
use App\Services\Seats\SeatService;
use Database\Seeders\OptiRadarApplicationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

/**
 * Seat-limit enforcement on the doors that are not the delegation API: the operator's application grant, the first Admin Tenant,
 * the manifest that declares the contract, the read-only inventory, the seeders - and a guard that no code writes an assignment
 * outside the central service. Real PostgreSQL. No operator bypass exists: an operator is judged exactly like an Admin Tenant.
 */
class SeatLimitPathsTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    private const GRANTS = ['cgo.tenant.view', 'cgo.tenant.admin.assign', 'cgo.user.create', 'cgo.user.tenant.assign', 'cgo.user.application.assign', 'cgo.user.role.assign', 'cgo.user.update', 'cgo.user.view', 'cgo.user.activate', 'cgo.user.email.verify'];

    private Application $entry;

    private Application $fleet;

    private Tenant $tenant;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Queue::fake();

        $this->seedGovernanceBaseline();
        $this->entry = $this->makeApplication(['application_code' => 'entry-test', 'name' => 'Entry Test', 'metadata' => ['manifest' => ['entitlement_limit_keys' => ['user_limit']]]]);
        $this->fleet = $this->makeApplication(['application_code' => 'fleet-test', 'name' => 'Fleet Test']);
        $this->tenant = $this->makeTenant(null, ['name' => 'Acme']);
        foreach ([$this->entry, $this->fleet] as $application) {
            $this->tenant->applications()->attach($application->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        }
        $this->operator = $this->makeOperatorWith(self::GRANTS);
        Sanctum::actingAs($this->operator);
    }

    private function limit(int|string $value, ?Application $application = null): Entitlement
    {
        return Entitlement::create([
            'tenant_id' => $this->tenant->id, 'application_id' => ($application ?? $this->entry)->id, 'entitlement_type' => Entitlement::TYPE_LIMIT,
            'entitlement_key' => 'user_limit', 'value' => (string) $value, 'status' => Entitlement::STATUS_ACTIVE,
            'source_type' => Entitlement::SOURCE_MANUAL_OVERRIDE, 'effective_from' => now()->subMinute(),
        ]);
    }

    private function person(string $name = 'Person'): User
    {
        $user = User::factory()->create(['name' => $name, 'status' => User::STATUS_ACTIVE]);
        TenantMembership::create(['user_id' => $user->id, 'tenant_id' => $this->tenant->id, 'status' => 'ACTIVE', 'is_tenant_admin' => false]);

        return $user;
    }

    private function grant(User $user, ?Application $application = null): TestResponse
    {
        return $this->postJson('/api/v1/users/'.$user->id.'/applications/'.($application ?? $this->entry)->id, ['tenant_id' => $this->tenant->id]);
    }

    private function used(?Application $application = null): int
    {
        return app(SeatService::class)->usage($this->tenant->id, ($application ?? $this->entry)->id)[0];
    }

    // ---------------------------------------------------------------- operator

    public function test_an_operator_is_judged_like_everyone_else_and_has_no_bypass(): void
    {
        $this->limit(1);
        $first = $this->person('First');
        $second = $this->person('Second');

        $this->grant($first)->assertCreated();
        $this->assertSame(1, $this->used());

        $this->grant($second)->assertStatus(409)->assertJsonPath('error.code', 'SEAT_LIMIT_REACHED')->assertJsonPath('error.details.recovery', 'FREE_SEAT_OR_RAISE_LIMIT');
        $this->assertFalse(UserApplicationAccess::query()->where('user_id', $second->id)->exists());
        $this->assertSame($this->operator->id, AuditLog::query()->where('action', 'seat.denied')->firstOrFail()->actor_user_id);

        // Granting again to someone who already holds the seat is not a second seat.
        $this->grant($first)->assertCreated();
        $this->assertSame(1, $this->used());

        // The other product of the same tenant is another budget (and has no seat contract here).
        $this->grant($second, $this->fleet)->assertCreated();
        $this->assertSame(1, $this->used());
    }

    public function test_an_operator_cannot_grant_without_a_limit_or_over_a_lowered_one_and_may_after_the_limit_is_raised(): void
    {
        $person = $this->person();
        $this->grant($person)->assertStatus(409)->assertJsonPath('error.code', 'SEAT_ENTITLEMENT_MISSING');

        $row = $this->limit(3);
        $this->grant($person)->assertCreated();
        $second = $this->person('Second');
        $this->grant($second)->assertCreated();

        $row->update(['value' => '1']);
        $third = $this->person('Third');
        $this->grant($third)->assertStatus(409)->assertJsonPath('error.code', 'SEAT_OVER_LIMIT');
        $this->assertSame(2, $this->used(), 'nobody was removed');

        $row->update(['value' => '3']);
        $this->grant($third)->assertCreated();
    }

    public function test_re_activating_a_revoked_assignment_needs_a_seat_again(): void
    {
        $this->limit(1);
        $person = $this->person();
        $other = $this->person('Other');
        $this->grant($person)->assertCreated();
        $this->deleteJson('/api/v1/users/'.$person->id.'/applications/'.$this->entry->id.'?tenant_id='.$this->tenant->id)->assertOk();
        $this->grant($other)->assertCreated();

        $this->grant($person)->assertStatus(409)->assertJsonPath('error.code', 'SEAT_LIMIT_REACHED');
        $this->assertSame(1, $this->used());
    }

    public function test_removing_the_membership_releases_every_seat_of_that_tenant(): void
    {
        $this->limit(1);
        $person = $this->person();
        $this->grant($person)->assertCreated();
        $this->assertSame(1, $this->used());

        $this->deleteJson('/api/v1/users/'.$person->id.'/tenants/'.$this->tenant->id)->assertOk();

        $this->assertSame(0, $this->used());
        $this->assertNotNull(User::query()->find($person->id), 'the global identity stays');
    }

    public function test_the_operator_sees_the_seats_of_a_tenant_for_every_application(): void
    {
        $this->limit(4);
        $this->grant($this->person())->assertCreated();

        $rows = collect($this->getJson('/api/v1/tenants/'.$this->tenant->id.'/seats')->assertOk()->json('data'))->keyBy('application_code');

        $this->assertSame('LIMITED', $rows['entry-test']['state']);
        $this->assertSame([1, 0, 4, 3], [$rows['entry-test']['used'], $rows['entry-test']['reserved'], $rows['entry-test']['limit'], $rows['entry-test']['available']]);
        $this->assertSame('NOT_CONTROLLED', $rows['fleet-test']['state']);
        $this->assertFalse($rows['fleet-test']['controlled']);
    }

    // ---------------------------------------------------------------- first Admin Tenant

    private function bootstrap(string $email = 'admin@acme.example', ?array $applications = null): TestResponse
    {
        return $this->postJson('/api/v1/ecosystem/tenants/'.$this->tenant->id.'/admin-accounts', [
            'name' => 'Acme Admin', 'email' => $email, 'application_ids' => $applications ?? [$this->entry->id],
        ]);
    }

    public function test_the_first_admin_tenant_takes_a_seat_and_is_refused_when_there_is_none(): void
    {
        $this->bootstrap()->assertStatus(409)->assertJsonPath('error.code', 'SEAT_ENTITLEMENT_MISSING');
        $this->assertSame(0, User::query()->where('email', 'admin@acme.example')->count(), 'nothing half-created');

        $this->limit(1);
        $this->bootstrap()->assertCreated();
        $this->assertSame(1, $this->used(), 'the Admin Tenant counts');

        $this->bootstrap('second.admin@acme.example')->assertStatus(409)->assertJsonPath('error.code', 'SEAT_LIMIT_REACHED');
        $this->assertSame(0, User::query()->where('email', 'second.admin@acme.example')->count());
        $this->assertSame(1, TenantMembership::query()->where('tenant_id', $this->tenant->id)->where('is_tenant_admin', true)->count());
    }

    public function test_one_application_without_room_refuses_the_whole_first_admin_tenant(): void
    {
        $this->limit(0);
        $fleetLimited = $this->makeApplication(['application_code' => 'fleet-seats', 'name' => 'Fleet Seats', 'metadata' => ['manifest' => ['entitlement_limit_keys' => ['user_limit']]]]);
        $this->tenant->applications()->attach($fleetLimited->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $this->limit(5, $fleetLimited);

        $this->bootstrap('admin@acme.example', [$fleetLimited->id, $this->entry->id])->assertStatus(409)->assertJsonPath('error.code', 'SEAT_LIMIT_REACHED');

        $this->assertSame(0, User::query()->where('email', 'admin@acme.example')->count());
        $this->assertSame(0, UserApplicationAccess::query()->count(), 'no access to the application that had room either');
        $this->assertSame(0, $this->used($fleetLimited));
    }

    public function test_an_application_without_the_seat_contract_does_not_block_the_first_admin_tenant(): void
    {
        $this->bootstrap('admin@acme.example', [$this->fleet->id])->assertCreated();
        $this->assertSame(1, $this->used($this->fleet));
    }

    // ---------------------------------------------------------------- the contract is declared by the product

    public function test_the_manifest_declares_the_seat_contract_and_an_omitted_list_changes_nothing(): void
    {
        $plain = $this->makeApplication(['application_code' => 'plain-app']);
        $send = fn (array $extra) => $this->postJson("/api/v1/applications/{$plain->id}/manifest", ['manifest_version' => '2.0', 'revision' => 'r'.Str::random(4)] + $extra);
        $this->actingAsSuperAdmin();
        $seats = app(SeatService::class);

        $this->assertFalse($seats->declares($plain->refresh()));

        $send(['entitlement_limit_keys' => ['user_limit', 'branch_limit']])->assertOk();
        $this->assertTrue($seats->declares($plain->refresh()));
        $this->assertSame(['user_limit', 'branch_limit'], $plain->metadata['manifest']['entitlement_limit_keys']);

        $send([])->assertOk();
        $this->assertTrue($seats->declares($plain->refresh()), 'a manifest that does not mention the limits leaves the declaration alone');

        $send(['entitlement_limit_keys' => ['branch_limit']])->assertOk();
        $this->assertFalse($seats->declares($plain->refresh()));

        $send(['entitlement_limit_keys' => ['Bad Key!']])->assertStatus(422);
    }

    // ---------------------------------------------------------------- read-only inventory and reconciliation

    public function test_the_inventory_reports_seats_and_anomalies_and_changes_nothing(): void
    {
        $row = $this->limit(3);
        $holders = collect(range(1, 3))->map(fn (int $i) => $this->person("P{$i}"));
        foreach ($holders as $holder) {
            UserApplicationAccess::create(['user_id' => $holder->id, 'tenant_id' => $this->tenant->id, 'application_id' => $this->entry->id, 'status' => 'ACTIVE']);
        }
        $deleted = $this->person('Deleted');
        UserApplicationAccess::create(['user_id' => $deleted->id, 'tenant_id' => $this->tenant->id, 'application_id' => $this->entry->id, 'status' => 'ACTIVE']);
        $deleted->delete();
        UserInvitation::create([
            'tenant_id' => $this->tenant->id, 'application_id' => $this->entry->id, 'email' => 'late@example.test', 'name' => 'Late', 'token_hash' => hash('sha256', 'x'),
            'status' => 'PENDING', 'invited_by' => $this->operator->id, 'expires_at' => now()->subDay(),
        ]);
        $row->update(['value' => '2']);

        $before = [UserApplicationAccess::count(), UserInvitation::count(), Entitlement::count(), User::withTrashed()->count(), AuditLog::count()];

        $this->assertSame(0, Artisan::call('ecosystem:seats:inventory', ['--tenant' => $this->tenant->tenant_code, '--application' => 'entry-test', '--json' => true]));
        $report = json_decode(Artisan::output(), true);
        $this->assertCount(1, $report);
        $this->assertSame([3, 0, 2, 1], [$report[0]['used'], $report[0]['reserved'], $report[0]['limit'], $report[0]['over_by']]);
        $this->assertTrue($report[0]['over_limit']);
        $this->assertSame(['expired_unstamped_invitations' => 1, 'redundant_invitations' => 0, 'assignments_of_deleted_identities' => 1, 'assignments_without_membership' => 0], $report[0]['anomalies']);
        $this->artisan('ecosystem:seats:inventory', ['--strict' => true, '--only-problems' => true])->assertFailed();

        $this->assertSame($before, [UserApplicationAccess::count(), UserInvitation::count(), Entitlement::count(), User::withTrashed()->count(), AuditLog::count()], 'read-only');
        $this->assertSame(UserInvitation::STATUS_PENDING, UserInvitation::query()->firstOrFail()->status, 'the stale invitation is reported, not stamped');

        $row->update(['value' => '10']);
        $this->artisan('ecosystem:seats:inventory', ['--strict' => true, '--tenant' => $this->tenant->tenant_code, '--application' => 'entry-test'])->assertSuccessful();
    }

    // ---------------------------------------------------------------- seeders

    public function test_a_seeder_obeys_the_same_rule_and_never_expands_an_entitlement(): void
    {
        $this->seed(OptiRadarApplicationSeeder::class);
        $radar = Application::query()->where('application_code', 'optiradar')->firstOrFail();

        // The demo tenant does not exist here, so seed it the way DemoDataSeeder would leave it, then make the product seat-controlled.
        $demo = $this->makeTenant(null, ['tenant_code' => OptiRadarApplicationSeeder::DEMO_TENANT_CODE]);
        User::factory()->create(['email' => OptiRadarApplicationSeeder::DEMO_USER_EMAIL, 'status' => 'ACTIVE']);
        $radar->update(['metadata' => ['manifest' => ['entitlement_limit_keys' => ['user_limit']]]]);
        $entitlements = Entitlement::count();

        try {
            $this->seed(OptiRadarApplicationSeeder::class);
            $this->fail('a seat-controlled product without a limit must not be seeded');
        } catch (SeatException $e) {
            $this->assertSame(SeatException::ENTITLEMENT_MISSING, $e->errorCode);
        }

        $this->assertSame(0, UserApplicationAccess::query()->where('tenant_id', $demo->id)->count());
        $this->assertSame($entitlements, Entitlement::count(), 'a seeder never writes entitlements');
    }

    // ---------------------------------------------------------------- guard

    public function test_no_code_writes_an_application_assignment_outside_the_seat_service(): void
    {
        $writers = '/UserApplicationAccess::(query\(\)->)?(create|firstOrCreate|updateOrCreate|insert|upsert|forceCreate)\b|applicationAccess\(\)->(create|firstOrCreate|updateOrCreate|save|insert)|DB::table\(.user_application_access.\)->(insert|upsert|update)/';
        $offenders = [];

        foreach ((new Finder)->files()->in([base_path('app'), base_path('database/seeders'), base_path('routes')])->name('*.php') as $file) {
            if ($file->getFilename() === 'SeatService.php') {
                continue;
            }

            if (preg_match($writers, $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'every assignment is created or re-activated by SeatService::grantAccess');
    }
}
