<?php

namespace Tests\Feature\Governance;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\OidcClient;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ecosystem\TenantAdminBootstrapService;
use App\Services\Oidc\OidcKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\TestCase;

/**
 * First Admin Tenant, the account's half (owner decision 2026-10-18, second batch): operator creates the account, the holder opens the
 * product, signs in to Nexus with the temporary password, must choose a password, and only then gets the OIDC code (code + PKCE).
 * Real pages, real sessions, real database; the operator side is driven through the service the API calls (its own tests are in
 * TenantAdminBootstrapApiTest).
 */
class AccountActivationFlowTest extends TestCase
{
    use CreatesIntegrationFixtures, RefreshDatabase;

    private const NEW_PASSWORD = 'My own long password 2026';

    private Tenant $tenant;

    private Application $fleet;

    private OidcClient $client;

    private string $secret;

    private User $operator;

    private User $admin;

    private string $temporary;

    private string $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Queue::fake();

        $this->seedGovernanceBaseline();
        $this->fleet = $this->makeApplication(['application_code' => 'fleet-test', 'name' => 'Fleet Test']);
        [$this->client, $this->secret] = $this->makeOidcClient($this->fleet);
        $this->tenant = $this->makeTenant(null, ['name' => 'Acme']);
        $this->tenant->applications()->attach($this->fleet->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $this->operator = $this->makeOperatorWith(['cgo.tenant.admin.assign', 'cgo.user.create', 'cgo.user.tenant.assign', 'cgo.user.application.assign', 'cgo.user.email.verify']);

        $result = app(TenantAdminBootstrapService::class)->provision(
            $this->tenant,
            ['name' => 'Acme Admin', 'email' => 'admin@acme.test', 'application_ids' => [$this->fleet->id]],
            $this->operator,
            Request::create('/'),
        );
        $this->admin = $result['user'];
        $this->temporary = $result['temporary_password'];
    }

    private function verifyEmail(): void
    {
        app(TenantAdminBootstrapService::class)->verifyEmail($this->tenant, $this->admin, $this->admin->email, 'Called the registered number and confirmed the address', $this->operator, Request::create('/'));
    }

    private function authorizeRequest(): TestResponse
    {
        [$this->verifier, $challenge] = $this->pkcePair();

        return $this->get('/oidc/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->client->client_id,
            'redirect_uri' => 'https://app.example.test/callback',
            'scope' => 'openid profile email',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertRedirect('/oidc/login');
    }

    private function signIn(?string $password = null, ?string $email = null): TestResponse
    {
        // The browser posts the form from the sign-in page, so a refusal goes back there.
        return $this->post('/oidc/login', ['email' => $email ?? $this->admin->email, 'password' => $password ?? $this->temporary], ['Referer' => url('/oidc/login')]);
    }

    private function choose(?string $temporary = null, ?string $password = self::NEW_PASSWORD, ?string $confirmation = null): TestResponse
    {
        return $this->post('/oidc/activate', [
            'temporary_password' => $temporary ?? $this->temporary,
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ]);
    }

    private function codeFrom(TestResponse $response): string
    {
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://app.example.test/callback', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query, 'Expected a code in '.$location);
        $this->assertSame('xyz', $query['state']);

        return $query['code'];
    }

    // ---------------------------------------------------------------- the temporary password opens one page and nothing else

    public function test_a_correct_temporary_password_leads_to_the_password_page_and_signs_nobody_in(): void
    {
        $this->authorizeRequest();

        // Typed the way it is read out: lower case, with the dashes.
        $this->signIn(strtolower($this->temporary))->assertRedirect('/oidc/activate');

        $this->assertGuest('web');
        $page = $this->get('/oidc/activate')->assertOk()->assertSee('Choose your password')->assertSee('admin@acme.test');
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $page->headers->get('Referrer-Policy'));

        // The open session cannot get a code: authorizing again still asks for the sign-in.
        $again = $this->get('/oidc/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => $this->client->client_id, 'redirect_uri' => 'https://app.example.test/callback', 'scope' => 'openid', 'state' => 's']));
        $again->assertRedirect('/oidc/login');
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(User::STATUS_INVITED, $this->admin->fresh()->status);
        $this->assertNotNull(AuditLog::query()->where('action', 'activation.started')->first());
    }

    public function test_the_password_page_is_unreachable_without_the_temporary_password(): void
    {
        $this->get('/oidc/activate')->assertStatus(400);
        $this->post('/oidc/activate', ['temporary_password' => $this->temporary, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertStatus(400);

        $this->assertTrue($this->admin->fresh()->password_change_required);
    }

    public function test_a_wrong_password_is_refused_like_any_other_wrong_password(): void
    {
        $this->authorizeRequest();

        $this->signIn('WRONG-WRONG-WRONG-WRONG')->assertRedirect('/oidc/login');
        $this->get('/oidc/login')->assertSee('do not match an active OptiNexus account');
        $this->assertGuest('web');
        $this->assertNotNull(AuditLog::query()->where('action', 'sso.login_failed')->first());
    }

    // ---------------------------------------------------------------- the whole path

    public function test_verified_then_password_chosen_then_the_code_flows_with_pkce(): void
    {
        $this->verifyEmail();
        $this->authorizeRequest();
        $this->signIn()->assertRedirect('/oidc/activate');
        $this->get('/oidc/activate')->assertOk();

        $response = $this->choose();
        $code = $this->codeFrom($response);

        $user = $this->admin->fresh();
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertNotNull($user->activated_at);
        $this->assertFalse($user->password_change_required);
        $this->assertNull($user->temporary_password_expires_at);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse(Hash::check(str_replace('-', '', $this->temporary), $user->password), 'the temporary password is consumed');
        $this->assertSame(User::ACTIVATION_ACTIVATED, $user->activationState());
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNull(session('oidc.activation'), 'the bootstrap session marker is gone');

        $token = $this->postJson('/api/oidc/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://app.example.test/callback',
            'client_id' => $this->client->client_id,
            'client_secret' => $this->secret,
            'code_verifier' => $this->verifier,
        ])->assertOk();

        $claims = app(OidcKeyService::class)->verify($token->json('id_token'));
        $this->assertSame($user->id, $claims['sub']);
        $this->assertSame($this->tenant->id, $claims['tenant_id']);
        $this->assertTrue($claims['email_verified'], 'the product sees the manual verification as a verified e-mail');
        $this->assertSame('admin@acme.test', $claims['email']);

        $this->assertNotNull(AuditLog::query()->where('action', 'activation.password_changed')->first());
        $this->assertNotNull(AuditLog::query()->where('action', 'activation.completed')->first());
        $this->assertNotNull(AuditLog::query()->where('action', 'user.sessions_ended')->first());
    }

    public function test_a_wrong_pkce_verifier_still_fails_for_the_new_admin(): void
    {
        $this->verifyEmail();
        $this->authorizeRequest();
        $this->signIn();
        $code = $this->codeFrom($this->choose());

        $this->postJson('/api/oidc/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://app.example.test/callback',
            'client_id' => $this->client->client_id, 'client_secret' => $this->secret, 'code_verifier' => Str::random(64),
        ])->assertStatus(400);
    }

    public function test_password_chosen_before_the_verification_waits_and_no_code_is_issued(): void
    {
        $this->authorizeRequest();
        $this->signIn();

        $pending = $this->choose();
        $pending->assertOk()->assertSee('Waiting for email verification');
        $this->assertNull($pending->headers->get('Location'));
        $this->assertGuest('web');

        $user = $this->admin->fresh();
        $this->assertSame(User::STATUS_INVITED, $user->status, 'no access before the operator records the verification');
        $this->assertFalse($user->password_change_required);
        $this->assertSame(User::ACTIVATION_EMAIL_VERIFICATION_PENDING, $user->activationState());

        // Signing in with the chosen password still leads nowhere until the operator is done.
        $this->authorizeRequest();
        $this->signIn(self::NEW_PASSWORD)->assertOk()->assertSee('Waiting for email verification');
        $this->assertGuest('web');
        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        // The operator's verification completes the activation; the next sign-in is an ordinary one.
        $this->verifyEmail();
        $this->assertSame(User::STATUS_ACTIVE, $this->admin->fresh()->status);

        $this->authorizeRequest();
        $this->codeFrom($this->signIn(self::NEW_PASSWORD));
    }

    // ---------------------------------------------------------------- limits, expiry, single use

    public function test_repeated_wrong_temporary_passwords_lock_the_account_for_a_while(): void
    {
        $this->authorizeRequest();

        foreach (range(1, 5) as $_) {
            $this->signIn('WRONG-WRONG-WRONG-WRONG')->assertRedirect('/oidc/login');
        }

        $this->signIn()->assertRedirect('/oidc/login');
        $this->get('/oidc/login')->assertSee('Too many attempts');
        $this->assertGuest('web');
        $this->assertNotNull(AuditLog::query()->where('action', 'activation.denied')->where('metadata->reason', 'locked')->first());

        $this->travel(16)->minutes();
        $this->signIn()->assertRedirect('/oidc/activate');
    }

    public function test_wrong_temporary_passwords_on_the_password_page_count_and_lock_too(): void
    {
        $this->authorizeRequest();
        $this->signIn()->assertRedirect('/oidc/activate');

        $this->choose('WRONG-WRONG-WRONG-WRONG')->assertStatus(422)->assertSee('4 attempts left');
        $this->assertTrue(Hash::check(str_replace('-', '', $this->temporary), $this->admin->fresh()->password), 'nothing changed');

        foreach (range(1, 4) as $_) {
            $this->choose('WRONG-WRONG-WRONG-WRONG');
        }

        $locked = $this->choose()->assertStatus(429)->assertSee('Too many attempts');
        $this->assertNotNull($locked->headers->get('Retry-After'));
        $this->assertTrue($this->admin->fresh()->password_change_required, 'even the right password is refused while locked');
    }

    public function test_an_expired_temporary_password_cannot_start_or_finish_the_activation(): void
    {
        $this->authorizeRequest();
        $this->signIn()->assertRedirect('/oidc/activate');

        $this->travel(49)->hours();

        $this->choose()->assertStatus(410)->assertSee('The temporary password has expired');
        $this->assertTrue($this->admin->fresh()->password_change_required);
        $this->assertSame(User::ACTIVATION_CREDENTIAL_EXPIRED, $this->admin->fresh()->activationState());

        $this->authorizeRequest();
        $this->signIn()->assertRedirect('/oidc/login');
        $this->get('/oidc/login')->assertSee('This temporary password has expired');
        $this->assertGuest('web');
    }

    public function test_a_reissued_password_cuts_off_the_session_opened_with_the_old_one(): void
    {
        $this->authorizeRequest();
        $this->signIn()->assertRedirect('/oidc/activate');

        $this->travel(2)->seconds();
        $reissued = app(TenantAdminBootstrapService::class)->reissue($this->tenant, $this->admin, $this->operator, Request::create('/'));

        $this->choose()->assertStatus(400);
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $this->admin->fresh()->password));

        $this->authorizeRequest();
        $this->signIn($this->temporary)->assertRedirect('/oidc/login');
        $this->signIn($reissued['temporary_password'])->assertRedirect('/oidc/activate');
    }

    public function test_the_temporary_password_works_once_and_a_replay_gets_nothing(): void
    {
        $this->verifyEmail();
        $this->authorizeRequest();
        $this->signIn();
        $this->codeFrom($this->choose());

        // Replay of the same page post, and a fresh sign-in with the consumed temporary password.
        $this->choose()->assertStatus(400);
        $this->get('/oidc/logout');
        $this->authorizeRequest();
        $this->signIn()->assertRedirect('/oidc/login');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->admin->fresh()->password), 'the chosen password was not touched');
    }

    public function test_the_new_password_must_be_long_confirmed_and_different(): void
    {
        $this->authorizeRequest();
        $this->signIn();
        $hash = $this->admin->fresh()->password;

        $this->choose(password: 'short')->assertStatus(422)->assertSee('at least 12 characters');
        $this->choose(password: self::NEW_PASSWORD, confirmation: 'Something else entirely 1')->assertStatus(422)->assertSee('not the same');
        $this->choose(password: $this->temporary)->assertStatus(422)->assertSee('different from the temporary one');
        $this->choose(password: strtolower($this->temporary))->assertStatus(422)->assertSee('different from the temporary one');

        $this->assertSame($hash, $this->admin->fresh()->password);
    }

    // ---------------------------------------------------------------- no governance portal, no product access in between

    public function test_choosing_a_password_never_gives_the_nexus_portal(): void
    {
        $this->verifyEmail();
        $this->authorizeRequest();
        $this->signIn();

        // Before the activation is complete: the account is not active, so nothing is issued for it.
        $this->postJson('/api/v1/auth/login', ['email' => $this->admin->email, 'password' => str_replace('-', '', $this->temporary)])->assertForbidden();

        $this->choose();

        $this->postJson('/api/v1/auth/login', ['email' => $this->admin->email, 'password' => self::NEW_PASSWORD])
            ->assertForbidden()->assertJsonPath('error.code', 'ECOSYSTEM_ACCESS_REQUIRED');
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_no_application_code_is_issued_to_an_account_that_has_not_finished(): void
    {
        $this->authorizeRequest();
        $this->signIn();
        $this->choose(); // password chosen, e-mail not verified yet

        $this->authorizeRequest();
        $response = $this->signIn(self::NEW_PASSWORD);
        $this->assertStringNotContainsString('code=', (string) $response->headers->get('Location'));
    }

    // ---------------------------------------------------------------- an existing identity is never touched

    public function test_a_linked_existing_identity_signs_in_with_its_own_password_and_meets_no_activation_step(): void
    {
        $existing = User::factory()->create(['email' => 'veteran@acme.test', 'status' => User::STATUS_ACTIVE, 'password' => 'Existing!Passw0rd']);
        $hash = $existing->password;
        $this->operator = $this->makeOperatorWith(['cgo.tenant.admin.assign', 'cgo.user.tenant.assign', 'cgo.user.application.assign']);
        app(TenantAdminBootstrapService::class)->provision($this->tenant, ['user_id' => $existing->id, 'confirm_email' => $existing->email, 'application_ids' => [$this->fleet->id]], $this->operator, Request::create('/'));

        $this->authorizeRequest();
        $this->codeFrom($this->signIn('Existing!Passw0rd', 'veteran@acme.test'));

        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertNull($existing->fresh()->activation_started_at);
    }

    // ---------------------------------------------------------------- language and mail

    public function test_the_pages_speak_english_and_bahasa_indonesia(): void
    {
        $this->authorizeRequest();
        $this->get('/oidc/login?lang=id')->assertOk()->assertSee('Masuk')->assertSee('Kata sandi');
        $this->signIn()->assertRedirect('/oidc/activate');
        $this->get('/oidc/activate')->assertOk()->assertSee('Pilih kata sandi Anda')->assertSee('Kata sandi sementara');
        $this->choose(password: 'pendek')->assertStatus(422)->assertSee('minimal 12 karakter');

        $this->get('/oidc/activate?lang=en')->assertSee('Choose your password');
    }

    public function test_the_browser_language_is_the_default(): void
    {
        $this->authorizeRequest();

        $this->get('/oidc/login', ['Accept-Language' => 'id-ID,id;q=0.9,en;q=0.5'])->assertOk()->assertSee('Masuk');
        $this->flushSession();
        $this->authorizeRequest();
        $this->get('/oidc/login', ['Accept-Language' => 'fr-FR,fr;q=0.9'])->assertOk()->assertSee('Sign in');
    }

    public function test_the_whole_path_sends_no_mail_notification_or_queued_job(): void
    {
        $this->verifyEmail();
        $this->authorizeRequest();
        $this->signIn();
        $this->codeFrom($this->choose());
        $this->get('/oidc/logout');
        $this->assertSame(User::STATUS_ACTIVE, $this->admin->fresh()->status);
    }

    public function test_no_secret_is_left_in_audit_records_or_the_log(): void
    {
        $this->verifyEmail();
        $this->authorizeRequest();
        $this->signIn();
        $this->choose();

        $everything = AuditLog::query()->get()->toJson();
        foreach ([$this->temporary, str_replace('-', '', $this->temporary), self::NEW_PASSWORD] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }

        $log = storage_path('logs/laravel.log');
        $this->assertTrue(! is_file($log) || ! str_contains((string) file_get_contents($log), self::NEW_PASSWORD));
        RateLimiter::clear('activation:temporary:'.$this->admin->id);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            Mail::assertNothingSent();
            Mail::assertNothingQueued();
            Notification::assertNothingSent();
            Queue::assertNothingPushed();
        }
        parent::tearDown();
    }
}
