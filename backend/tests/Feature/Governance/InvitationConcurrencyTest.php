<?php

namespace Tests\Feature\Governance;

use App\Models\Application;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserInvitation;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Onboarding by invitation: concurrent acceptance. Real PostgreSQL and real parallel PHP processes (each with its own connection and
 * transaction); nothing is faked. The row lock on the invitation makes exactly one acceptance win; the unique e-mail constraint keeps
 * two invitations for the same new person from creating two identities.
 *
 * The test transaction of RefreshDatabase is invisible to other processes, so the fixtures are committed through a separate
 * connection and removed again afterwards.
 */
class InvitationConcurrencyTest extends TestCase
{
    private const WORKER = 'tests/Concurrency/invitation_accept_worker.php';

    /** @var array<string, list<string>> table => ids created by this test */
    private array $created = [];

    protected function tearDown(): void
    {
        $db = DB::connection('concurrency');
        $emails = $db->table('users')->whereIn('id', $this->created['users'] ?? [])->pluck('id')->all();
        $db->table('user_invitations')->whereIn('id', $this->created['invitations'] ?? [])->delete();
        $db->table('user_roles')->whereIn('user_id', $emails)->delete();
        $db->table('user_application_access')->whereIn('user_id', $emails)->delete();
        $db->table('tenant_memberships')->whereIn('user_id', $emails)->delete();
        $db->table('tenant_applications')->whereIn('tenant_id', $this->created['tenants'] ?? [])->delete();
        $db->table('users')->whereIn('id', $emails)->delete();
        $db->table('tenants')->whereIn('id', $this->created['tenants'] ?? [])->delete();
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

    /** @return array{0: Application, 1: User} */
    private function product(): array
    {
        $this->db();
        $application = Application::on('concurrency')->create(['application_code' => 'race-'.Str::lower(Str::random(6)), 'name' => 'Race', 'status' => Application::STATUS_PUBLISHED]);
        $this->remember('applications', $application->id);

        return [$application, $this->tenantAdmin($application, 'first')[1]];
    }

    /** @return array{0: Tenant, 1: User} a usable tenant with the product and an Admin Tenant who holds access */
    private function tenantAdmin(Application $application, string $label): array
    {
        $customer = Customer::on('concurrency')->create(['customer_code' => 'C-'.Str::upper(Str::random(6)), 'legal_name' => "Race {$label}", 'status' => Customer::STATUS_ACTIVE]);
        $tenant = Tenant::on('concurrency')->create(['tenant_code' => 'T-'.Str::upper(Str::random(6)), 'customer_id' => $customer->id, 'name' => "Race {$label}", 'status' => Tenant::STATUS_ACTIVE]);
        $tenant->applications()->attach($application->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $admin = User::on('concurrency')->create(['name' => "Admin {$label}", 'email' => "admin-{$label}-".Str::lower(Str::random(6)).'@race.test', 'password' => 'unused admin password 1', 'status' => User::STATUS_ACTIVE]);
        TenantMembership::on('concurrency')->create(['user_id' => $admin->id, 'tenant_id' => $tenant->id, 'status' => 'ACTIVE', 'is_tenant_admin' => true]);
        UserApplicationAccess::on('concurrency')->create(['user_id' => $admin->id, 'tenant_id' => $tenant->id, 'application_id' => $application->id, 'status' => 'ACTIVE']);

        foreach ([['customers', $customer->id], ['tenants', $tenant->id], ['users', $admin->id]] as [$table, $id]) {
            $this->remember($table, $id);
        }

        return [$tenant, $admin];
    }

    private function invitation(Tenant $tenant, Application $application, User $admin, string $email): UserInvitation
    {
        $invitation = UserInvitation::on('concurrency')->create([
            'tenant_id' => $tenant->id, 'application_id' => $application->id, 'email' => $email, 'name' => 'Racer', 'locale' => 'en', 'role_ids' => [],
            'token_hash' => hash('sha256', Str::random(40)), 'status' => 'PENDING', 'invited_by' => $admin->id, 'expires_at' => now()->addDay(), 'send_count' => 1,
        ]);
        $this->remember('invitations', $invitation->id);

        return $invitation;
    }

    /** @return array{0: resource, 1: array<int, resource>} */
    private function start(string $label, UserInvitation $invitation, float $startAt, string $password): array
    {
        $env = getenv();
        foreach (['APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'CACHE_STORE', 'APP_KEY'] as $key) {
            if (($value = env($key)) !== null) {
                $env[$key] = (string) $value;
            }
        }
        $process = proc_open(
            [PHP_BINARY, base_path(self::WORKER), $label, $invitation->id, $invitation->token_hash, sprintf('%.6F', $startAt), $password],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env,
        );

        return [$process, $pipes];
    }

    /** @param  array<int, array{0: resource, 1: array<int, resource>}>  $workers @return list<string> outcomes */
    private function collect(array $workers): array
    {
        $outcomes = [];
        foreach ($workers as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $line = json_decode(trim($out), true);
            $this->assertIsArray($line, "worker produced no result. stderr: {$err}");
            $outcomes[] = $line['outcome'];
        }

        return $outcomes;
    }

    public function test_six_parallel_acceptances_of_one_invitation_create_exactly_one_identity(): void
    {
        [$application, $admin] = $this->product();
        $tenant = Tenant::on('concurrency')->whereKey($this->created['tenants'][0])->firstOrFail();
        $invitation = $this->invitation($tenant, $application, $admin, 'racer@race.test');

        $startAt = microtime(true) + 3;
        $workers = array_map(fn (int $n) => $this->start("w{$n}", $invitation, $startAt, 'a long racing password 1'), range(1, 6));
        $outcomes = $this->collect($workers);

        $this->assertSame(1, count(array_keys($outcomes, 'accepted', true)), 'exactly one acceptance wins: '.json_encode($outcomes));
        $this->assertSame(5, count(array_keys($outcomes, 'used', true)), 'every other attempt is told the invitation was used: '.json_encode($outcomes));

        $db = $this->db();
        $this->assertSame(1, $db->table('users')->where('email', 'racer@race.test')->count());
        $userId = $db->table('users')->where('email', 'racer@race.test')->value('id');
        $this->remember('users', $userId);
        $this->assertSame(1, $db->table('tenant_memberships')->where('user_id', $userId)->count());
        $this->assertSame(1, $db->table('user_application_access')->where('user_id', $userId)->count());
        $this->assertSame('ACCEPTED', $db->table('user_invitations')->where('id', $invitation->id)->value('status'));
        $this->assertNotNull($db->table('users')->where('id', $userId)->value('email_verified_at'));
    }

    public function test_two_invitations_for_the_same_new_person_never_create_two_identities(): void
    {
        [$application, $admin] = $this->product();
        $first = Tenant::on('concurrency')->whereKey($this->created['tenants'][0])->firstOrFail();
        [$second, $secondAdmin] = $this->tenantAdmin($application, 'second');
        $a = $this->invitation($first, $application, $admin, 'twin@race.test');
        $b = $this->invitation($second, $application, $secondAdmin, 'twin@race.test');

        $startAt = microtime(true) + 3;
        $outcomes = $this->collect([
            $this->start('a', $a, $startAt, 'a long racing password 1'),
            $this->start('b', $b, $startAt, 'a long racing password 2'),
        ]);

        $db = $this->db();
        $this->assertSame(1, $db->table('users')->where('email', 'twin@race.test')->count(), 'never two identities: '.json_encode($outcomes));
        $userId = $db->table('users')->where('email', 'twin@race.test')->value('id');
        $this->remember('users', $userId);

        // One of them created the identity; the other cannot set a second password for it and must confirm the existing account instead.
        $this->assertContains('accepted', $outcomes);
        foreach ($outcomes as $outcome) {
            $this->assertContains($outcome, ['accepted', 'wrong_password', 'conflict'], json_encode($outcomes));
        }
        $winner = $outcomes[0] === 'accepted' ? $a : $b;
        $loser = $outcomes[0] === 'accepted' ? $b : $a;
        $this->assertSame(1, $db->table('tenant_memberships')->where('user_id', $userId)->count());
        $this->assertSame($winner->tenant_id, $db->table('tenant_memberships')->where('user_id', $userId)->value('tenant_id'));
        $this->assertSame('PENDING', $db->table('user_invitations')->where('id', $loser->id)->value('status'), 'the refused invitation is still open for a retry with the account password');
    }
}
