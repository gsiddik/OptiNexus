<?php

namespace Tests\Concerns;

use App\Mail\UserInvitationMail;
use App\Models\Application;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserInvitation;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * One tenant, one product ("entry-test") with a delegable permission set, an Admin Tenant, the product's service account token,
 * and helpers to invite through the delegation API and to read the link out of the (faked) e-mail.
 */
trait CreatesInvitationFixtures
{
    use CreatesGovernanceFixtures, IssuesServiceAccountTokens;

    private Application $entry;

    private Application $otherApp;

    private Tenant $tenant;

    private User $admin;

    private string $token;

    private ServiceAccount $account;

    /** @var array<string, Permission> */
    private array $perm = [];

    private Role $clerk;

    /** The real mail manager, kept so a test can exercise a genuine transport failure after Mail::fake(). */
    private MailManager $realMailManager;

    protected function setUpInvitationWorld(): void
    {
        $this->seedGovernanceBaseline();
        $this->realMailManager = app('mail.manager');
        Mail::fake();

        $this->entry = $this->makeApplication(['application_code' => 'entry-test', 'name' => 'Entry Test', 'frontend_url' => 'https://entry.test']);
        $this->otherApp = $this->makeApplication(['application_code' => 'other-test']);
        $this->tenant = $this->makeTenant(null, ['name' => 'Acme Test']);
        $this->tenant->applications()->attach($this->entry->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);

        $this->perm['view'] = $this->makePermission($this->entry, 'entry-test.journal.view');
        $this->perm['post'] = $this->makePermission($this->entry, 'entry-test.journal.post');
        $this->perm['close'] = $this->makePermission($this->entry, 'entry-test.period.close');
        foreach (['view', 'post'] as $key) {
            $this->perm[$key]->update(['delegable' => true]);
        }

        $this->clerk = $this->applicationRole($this->tenant, 'CLERK', ['entry-test.journal.view']);
        $this->admin = $this->member(admin: true, name: 'Tenant Admin');
        [$this->token, $this->account] = $this->issueServiceAccountTokenFor($this->entry->id, ['tenant.delegate']);
    }

    /** @param  array<int, string>  $permissionKeys */
    protected function applicationRole(Tenant $tenant, string $code, array $permissionKeys): Role
    {
        return $this->makeTenantRole($tenant, $permissionKeys, ['code' => $code, 'name' => ucfirst(strtolower($code)), 'role_type' => Role::TYPE_APPLICATION, 'application_id' => $this->entry->id]);
    }

    protected function member(bool $admin = false, string $name = 'Member', ?Tenant $tenant = null, bool $access = true, string $status = User::STATUS_ACTIVE, array $user = []): User
    {
        $tenant ??= $this->tenant;
        $model = User::factory()->create(['name' => $name, 'status' => $status] + $user);
        TenantMembership::create(['user_id' => $model->id, 'tenant_id' => $tenant->id, 'status' => TenantMembership::STATUS_ACTIVE, 'is_tenant_admin' => $admin]);
        if ($access) {
            UserApplicationAccess::create(['user_id' => $model->id, 'tenant_id' => $tenant->id, 'application_id' => $this->entry->id, 'status' => UserApplicationAccess::STATUS_ACTIVE]);
        }

        return $model;
    }

    protected function delegate(string $method, string $path, array $body = [], ?User $actor = null, ?string $token = null, ?Tenant $tenant = null): TestResponse
    {
        $tenant ??= $this->tenant;
        $payload = $body + ['acting_user_id' => ($actor ?? $this->admin)->id];
        $url = "/api/v1/delegation/tenants/{$tenant->id}{$path}";
        $client = $this->withHeader('Authorization', 'Bearer '.($token ?? $this->token));

        return in_array($method, ['GET', 'DELETE'], true)
            ? $client->json($method, $url.(str_contains($url, '?') ? '&' : '?').http_build_query($payload))
            : $client->json($method, $url, $payload);
    }

    protected function invite(string $email = 'new.person@example.test', array $extra = [], ?User $actor = null): TestResponse
    {
        return $this->delegate('POST', '/invitations', $extra + ['email' => $email, 'name' => 'New Person', 'role_ids' => [$this->clerk->id]], $actor);
    }

    /** The raw token of the most recent invitation e-mail to this address (the only place it exists). */
    protected function tokenSentTo(string $email): string
    {
        $link = null;
        Mail::assertSent(UserInvitationMail::class, function (UserInvitationMail $mail) use ($email, &$link): bool {
            if ($mail->hasTo($email)) {
                $link = $mail->link;
            }

            return true;
        });

        $this->assertNotNull($link, "no invitation e-mail was sent to {$email}");

        return basename(parse_url($link, PHP_URL_PATH));
    }

    protected function invitationFor(string $email): UserInvitation
    {
        return UserInvitation::query()->where('email', strtolower($email))->latest('created_at')->firstOrFail();
    }
}
