<?php

namespace Tests\Feature\Governance;

use App\Models\Application;
use App\Models\Customer;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserInvitation;
use App\Services\Seats\SeatService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Seat limit under contention: real PostgreSQL and real parallel PHP processes, each with its own connection and transaction. The
 * last seat is contested through DIFFERENT doors at the same instant (an Admin Tenant's invitation, the deprecated direct add, the
 * operator/seeder grant, an invitee's acceptance); the per-tenant-application seat lock must let exactly as many through as there
 * are seats - never one more - and a loser must leave nothing behind.
 *
 * The test transaction of RefreshDatabase is invisible to other processes, so the fixtures are committed through a separate
 * connection and removed again afterwards.
 */
class SeatConcurrencyTest extends TestCase
{
    private const WORKER = 'tests/Concurrency/seat_worker.php';

    /** @var array<string, list<string>> table => ids created by this test */
    private array $created = [];

    protected function tearDown(): void
    {
        $db = DB::connection('concurrency');
        $tenants = $this->created['tenants'] ?? [];
        $people = $db->table('users')->where('email', 'like', '%@race.test')->pluck('id')->all();
        $db->table('user_invitations')->whereIn('tenant_id', $tenants)->delete();
        $db->table('entitlements')->whereIn('tenant_id', $tenants)->delete();
        $db->table('user_roles')->whereIn('user_id', $people)->delete();
        $db->table('user_application_access')->whereIn('tenant_id', $tenants)->delete();
        $db->table('tenant_memberships')->whereIn('tenant_id', $tenants)->delete();
        $db->table('tenant_applications')->whereIn('tenant_id', $tenants)->delete();
        $db->table('audit_logs')->whereIn('tenant_id', $tenants)->delete();
        $db->table('users')->whereIn('id', $people)->delete();
        $db->table('tenants')->whereIn('id', $tenants)->delete();
        $db->table('customers')->whereIn('id', $this->created['customers'] ?? [])->delete();
        $db->table('applications')->whereIn('id', $this->created['applications'] ?? [])->delete();
        $db->disconnect();
        parent::tearDown();
    }

    private function db(): Connection
    {
        config(['database.connections.concurrency' => config('database.connections.pgsql')]);

        return DB::connection('concurrency');
    }

    private function remember(string $table, string $id): string
    {
        $this->created[$table][] = $id;

        return $id;
    }

    /** @return array{0: Application, 1: Tenant, 2: User} a seat-controlled product, a tenant with `$limit` seats, and its Admin Tenant (who holds one) */
    private function world(int $limit): array
    {
        $this->db();
        $application = Application::on('concurrency')->create([
            'application_code' => 'seat-'.Str::lower(Str::random(6)), 'name' => 'Seat Race', 'status' => Application::STATUS_PUBLISHED,
            'metadata' => ['manifest' => ['entitlement_limit_keys' => ['user_limit']]],
        ]);
        $customer = Customer::on('concurrency')->create(['customer_code' => 'C-'.Str::upper(Str::random(6)), 'legal_name' => 'Seat Race', 'status' => Customer::STATUS_ACTIVE]);
        $tenant = Tenant::on('concurrency')->create(['tenant_code' => 'T-'.Str::upper(Str::random(6)), 'customer_id' => $customer->id, 'name' => 'Seat Race', 'status' => Tenant::STATUS_ACTIVE]);
        $tenant->applications()->attach($application->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $admin = $this->person('admin');
        TenantMembership::on('concurrency')->create(['user_id' => $admin->id, 'tenant_id' => $tenant->id, 'status' => 'ACTIVE', 'is_tenant_admin' => true]);
        UserApplicationAccess::on('concurrency')->create(['user_id' => $admin->id, 'tenant_id' => $tenant->id, 'application_id' => $application->id, 'status' => 'ACTIVE']);
        Entitlement::on('concurrency')->create([
            'tenant_id' => $tenant->id, 'application_id' => $application->id, 'entitlement_type' => 'LIMIT', 'entitlement_key' => 'user_limit',
            'value' => (string) $limit, 'status' => 'ACTIVE', 'source_type' => 'MANUAL_OVERRIDE', 'effective_from' => now()->subMinute(),
        ]);

        foreach ([['applications', $application->id], ['customers', $customer->id], ['tenants', $tenant->id]] as [$table, $id]) {
            $this->remember($table, $id);
        }

        return [$application, $tenant, $admin];
    }

    private function person(string $label): User
    {
        $user = User::on('concurrency')->create(['name' => "Racer {$label}", 'email' => "{$label}-".Str::lower(Str::random(6)).'@race.test', 'password' => 'unused race password 12', 'status' => User::STATUS_ACTIVE]);
        $this->remember('users', $user->id);

        return $user;
    }

    /** A person who exists and belongs to the tenant but holds no assignment yet (the operator/seeder door grants it). */
    private function member(Tenant $tenant, string $label): User
    {
        $user = $this->person($label);
        TenantMembership::on('concurrency')->create(['user_id' => $user->id, 'tenant_id' => $tenant->id, 'status' => 'ACTIVE', 'is_tenant_admin' => false]);

        return $user;
    }

    private function invitation(Tenant $tenant, Application $application, User $admin, string $email): UserInvitation
    {
        return UserInvitation::on('concurrency')->create([
            'tenant_id' => $tenant->id, 'application_id' => $application->id, 'email' => $email, 'name' => 'Racer', 'locale' => 'en', 'role_ids' => [],
            'token_hash' => hash('sha256', Str::random(40)), 'status' => 'PENDING', 'invited_by' => $admin->id, 'expires_at' => now()->addDay(), 'send_count' => 1,
        ]);
    }

    /**
     * @param  array{0: string, 1: string, 2?: string}  $args  door, arg1, arg2
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function start(string $label, array $args, Tenant $tenant, Application $application, User $admin, float $startAt): array
    {
        $env = getenv();
        foreach (['APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'CACHE_STORE', 'APP_KEY', 'MAIL_MAILER', 'QUEUE_CONNECTION'] as $key) {
            if (($value = env($key)) !== null) {
                $env[$key] = (string) $value;
            }
        }
        [$door, $arg1] = $args;
        $command = [PHP_BINARY, base_path(self::WORKER), $door, $label, sprintf('%.6F', $startAt), $tenant->id, $application->id, $admin->id, $arg1];
        if (isset($args[2])) {
            $command[] = $args[2];
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);

        return [$process, $pipes];
    }

    /** @param  array<int, array{0: resource, 1: array<int, resource>}>  $workers @return list<array{worker: string, door: string, outcome: string}> */
    private function collect(array $workers): array
    {
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $line = json_decode(trim($out), true);
            $this->assertIsArray($line, "worker produced no result. stderr: {$err}");
            $results[] = $line;
        }

        return $results;
    }

    /** @return array{0: int, 1: int} used, reserved - from the committed rows, through the same counting the API uses */
    private function figures(Tenant $tenant, Application $application): array
    {
        return app(SeatService::class)->usage($tenant->id, $application->id);
    }

    public function test_the_last_seats_are_contested_through_every_door_and_exactly_as_many_get_through_as_there_are_seats(): void
    {
        [$application, $tenant, $admin] = $this->world(limit: 3); // the admin holds one: two seats are free
        $startAt = microtime(true) + 4.0;
        $workers = [];

        foreach (range(1, 3) as $i) {
            $workers[] = $this->start("invite{$i}", ['invite', "invite{$i}-".Str::lower(Str::random(5)).'@race.test'], $tenant, $application, $admin, $startAt);
            $workers[] = $this->start("direct{$i}", ['direct', "direct{$i}-".Str::lower(Str::random(5)).'@race.test'], $tenant, $application, $admin, $startAt);
            $workers[] = $this->start("grant{$i}", ['grant', $this->member($tenant, "grant{$i}")->id], $tenant, $application, $admin, $startAt);
        }

        $results = $this->collect($workers);
        $outcomes = array_column($results, 'outcome');

        $this->assertSame(['SEAT_LIMIT_REACHED'], array_values(array_unique(array_diff($outcomes, ['ok']))), 'every loser is told the limit was reached: '.json_encode($results));
        $this->assertCount(2, array_keys($outcomes, 'ok', true), 'exactly the two free seats were given: '.json_encode($results));

        [$used, $reserved] = $this->figures($tenant, $application);
        $this->assertSame(3, $used + $reserved, 'never above the limit');
        $this->assertSame(
            $used,
            UserApplicationAccess::on('concurrency')->where('tenant_id', $tenant->id)->where('status', 'ACTIVE')->distinct()->count('user_id'),
        );

        // A loser leaves nothing behind: no identity from a refused direct add, no invitation row from a refused invite.
        $wonDirect = count(array_filter($results, fn ($r) => $r['door'] === 'direct' && $r['outcome'] === 'ok'));
        $this->assertSame($wonDirect, User::on('concurrency')->where('email', 'like', 'direct%@race.test')->count());
        $wonInvite = count(array_filter($results, fn ($r) => $r['door'] === 'invite' && $r['outcome'] === 'ok'));
        $this->assertSame($wonInvite, UserInvitation::on('concurrency')->where('tenant_id', $tenant->id)->count());
        $this->assertSame($used, TenantMembership::on('concurrency')->where('tenant_id', $tenant->id)->whereIn('user_id', UserApplicationAccess::on('concurrency')->where('tenant_id', $tenant->id)->select('user_id'))->count());
    }

    public function test_an_acceptance_converts_its_reservation_while_every_other_door_is_turned_away(): void
    {
        [$application, $tenant, $admin] = $this->world(limit: 2); // the admin holds one, the pending invitation reserves the other
        $pending = $this->invitation($tenant, $application, $admin, 'holder-'.Str::lower(Str::random(5)).'@race.test');
        $startAt = microtime(true) + 4.0;
        $workers = [$this->start('accept', ['accept', $pending->id, $pending->token_hash], $tenant, $application, $admin, $startAt)];

        foreach (range(1, 2) as $i) {
            $workers[] = $this->start("invite{$i}", ['invite', "invite{$i}-".Str::lower(Str::random(5)).'@race.test'], $tenant, $application, $admin, $startAt);
            $workers[] = $this->start("direct{$i}", ['direct', "direct{$i}-".Str::lower(Str::random(5)).'@race.test'], $tenant, $application, $admin, $startAt);
            $workers[] = $this->start("grant{$i}", ['grant', $this->member($tenant, "grant{$i}")->id], $tenant, $application, $admin, $startAt);
        }

        $results = $this->collect($workers);
        $byWorker = array_column($results, 'outcome', 'worker');

        $this->assertSame('ok', $byWorker['accept'], 'the reservation holder always gets the seat: '.json_encode($results));
        unset($byWorker['accept']);
        $this->assertSame(['SEAT_LIMIT_REACHED'], array_values(array_unique($byWorker)), json_encode($results));
        $this->assertSame([2, 0], $this->figures($tenant, $application), 'reserved became used, once');
        $this->assertSame('ACCEPTED', UserInvitation::on('concurrency')->find($pending->id)->status);
    }

    public function test_the_same_person_arriving_by_invitation_and_by_direct_add_at_once_takes_one_seat(): void
    {
        [$application, $tenant, $admin] = $this->world(limit: 2); // one seat is free and two doors want it for the same e-mail
        $email = 'twin-'.Str::lower(Str::random(5)).'@race.test';
        $startAt = microtime(true) + 4.0;

        $results = $this->collect([
            $this->start('invite', ['invite', $email], $tenant, $application, $admin, $startAt),
            $this->start('direct', ['direct', $email], $tenant, $application, $admin, $startAt),
        ]);

        $outcomes = array_column($results, 'outcome', 'door');
        $this->assertContains($outcomes['direct'], ['ok', 'SEAT_LIMIT_REACHED'], json_encode($results));
        $this->assertContains($outcomes['invite'], ['ok', 'SEAT_LIMIT_REACHED', 'ALREADY_MEMBER'], json_encode($results));

        [$used, $reserved] = $this->figures($tenant, $application);
        $this->assertSame(2, $used + $reserved, 'the admin plus one seat for the twin: a person never holds two seats ('.json_encode($results).')');
        $this->assertLessThanOrEqual(1, User::on('concurrency')->where('email', $email)->count());
    }
}
