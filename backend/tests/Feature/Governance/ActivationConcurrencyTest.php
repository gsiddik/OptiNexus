<?php

namespace Tests\Feature\Governance;

use App\Models\Application;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Services\Ecosystem\AccountActivationService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * First Admin Tenant: concurrent activation. Real PostgreSQL and real parallel PHP processes (each with its own connection and
 * transaction); nothing is faked. The row lock on the account makes exactly one submission of a temporary password win - the account
 * never ends up with two chosen passwords, and the verification and the password change cannot both "complete" the activation twice.
 *
 * The test transaction of RefreshDatabase is invisible to other processes, so the fixtures are committed through a separate
 * connection and removed again afterwards.
 */
class ActivationConcurrencyTest extends TestCase
{
    private const WORKER = 'tests/Concurrency/activation_password_worker.php';

    /** @var array<string, list<string>> table => ids created by this test */
    private array $created = [];

    protected function tearDown(): void
    {
        $db = DB::connection('concurrency');
        $users = $this->created['users'] ?? [];
        $db->table('audit_logs')->whereIn('resource_id', $users)->delete();
        $db->table('user_application_access')->whereIn('user_id', $users)->delete();
        $db->table('tenant_memberships')->whereIn('user_id', $users)->delete();
        $db->table('tenant_applications')->whereIn('tenant_id', $this->created['tenants'] ?? [])->delete();
        $db->table('users')->whereIn('id', $users)->delete();
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

    private function remember(string $table, string $id): void
    {
        $this->created[$table][] = $id;
    }

    /** @return array{0: User, 1: string} an account in activation (temporary password issued, e-mail verified by an operator) and that password */
    private function pendingAdmin(bool $verified): array
    {
        $this->db();
        $application = Application::on('concurrency')->create(['application_code' => 'race-'.Str::lower(Str::random(6)), 'name' => 'Race', 'status' => Application::STATUS_PUBLISHED]);
        $customer = Customer::on('concurrency')->create(['customer_code' => 'C-'.Str::upper(Str::random(6)), 'legal_name' => 'Race', 'status' => Customer::STATUS_ACTIVE]);
        $tenant = Tenant::on('concurrency')->create(['tenant_code' => 'T-'.Str::upper(Str::random(6)), 'customer_id' => $customer->id, 'name' => 'Race', 'status' => Tenant::STATUS_ACTIVE]);
        $tenant->applications()->attach($application->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);

        $user = User::on('concurrency')->create(['name' => 'Racer', 'email' => 'racer-'.Str::lower(Str::random(6)).'@race.test', 'status' => User::STATUS_INVITED]);
        $user->forceFill(['activation_started_at' => now(), 'email_verified_at' => $verified ? now() : null, 'email_verification_method' => $verified ? User::EMAIL_VERIFIED_BY_OPERATOR : null]);
        $temporary = app(AccountActivationService::class)->issueTemporaryPassword($user);
        TenantMembership::on('concurrency')->create(['user_id' => $user->id, 'tenant_id' => $tenant->id, 'status' => 'ACTIVE', 'is_tenant_admin' => true]);
        UserApplicationAccess::on('concurrency')->create(['user_id' => $user->id, 'tenant_id' => $tenant->id, 'application_id' => $application->id, 'status' => 'ACTIVE']);

        foreach ([['applications', $application->id], ['customers', $customer->id], ['tenants', $tenant->id], ['users', $user->id]] as [$table, $id]) {
            $this->remember($table, $id);
        }

        return [$user->refresh(), $temporary];
    }

    /** @return array{0: resource, 1: array<int, resource>} */
    private function start(string $label, User $user, string $temporary, string $newPassword, float $startAt): array
    {
        $env = getenv();
        foreach (['APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'CACHE_STORE', 'APP_KEY'] as $key) {
            if (($value = env($key)) !== null) {
                $env[$key] = (string) $value;
            }
        }
        $process = proc_open(
            [PHP_BINARY, base_path(self::WORKER), $label, $user->id, (string) $user->temporary_password_issued_at->timestamp, $temporary, $newPassword, sprintf('%.6F', $startAt)],
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

    public function test_six_parallel_submissions_of_one_temporary_password_change_it_exactly_once(): void
    {
        [$user, $temporary] = $this->pendingAdmin(verified: true);

        $startAt = microtime(true) + 3;
        $workers = [];
        foreach (range(1, 6) as $n) {
            $workers[] = $this->start("w{$n}", $user, $temporary, "A long racing password {$n}", $startAt);
        }
        $outcomes = $this->collect($workers);

        $this->assertSame(1, count(array_keys($outcomes, 'changed', true)), 'exactly one submission wins: '.json_encode($outcomes));
        $this->assertSame(5, count(array_keys($outcomes, 'used', true)), 'every other one is told the temporary password was used: '.json_encode($outcomes));

        $row = $this->db()->table('users')->where('id', $user->id)->first();
        $this->assertSame('ACTIVE', $row->status, 'verified + password chosen = activated, once');
        $this->assertNotNull($row->activated_at);
        $this->assertFalse((bool) $row->password_change_required);

        $winner = (int) array_search('changed', $outcomes, true) + 1;
        $this->assertTrue(Hash::check("A long racing password {$winner}", $row->password), 'the chosen password is the winner\'s, not a mix');
        $this->assertSame(1, $this->db()->table('audit_logs')->where('resource_id', $user->id)->where('action', 'activation.completed')->count());
    }

    public function test_parallel_submissions_before_the_verification_leave_the_account_waiting_with_one_password(): void
    {
        [$user, $temporary] = $this->pendingAdmin(verified: false);

        $startAt = microtime(true) + 3;
        $outcomes = $this->collect([
            $this->start('a', $user, $temporary, 'First racing password 1', $startAt),
            $this->start('b', $user, $temporary, 'Second racing password 2', $startAt),
        ]);

        $this->assertEqualsCanonicalizing(['changed', 'used'], $outcomes);
        $row = $this->db()->table('users')->where('id', $user->id)->first();
        $this->assertSame('INVITED', $row->status, 'no access until the operator has verified the address');
        $this->assertNull($row->activated_at);
        $this->assertSame(1, $this->db()->table('audit_logs')->where('resource_id', $user->id)->where('action', 'activation.password_changed')->count());
    }
}
