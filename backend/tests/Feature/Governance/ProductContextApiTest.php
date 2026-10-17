<?php

namespace Tests\Feature\Governance;

use App\Models\Application;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserApplicationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\IssuesServiceAccountTokens;
use Tests\TestCase;

/** Audit A29: the data behind a product switcher - only what the person may open, in the tenant they use, never the portal. */
class ProductContextApiTest extends TestCase
{
    use CreatesGovernanceFixtures, IssuesServiceAccountTokens, RefreshDatabase;

    private const ENDPOINT = '/api/v1/authorization/product-context';

    private Application $entry;

    private Application $fleet;

    private Application $radar;

    private Tenant $tenant;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedGovernanceBaseline();
        $this->entry = $this->makeApplication(['application_code' => 'optiaccounting', 'name' => 'OptiEntry', 'frontend_url' => 'https://entry.example.test']);
        $this->fleet = $this->makeApplication(['application_code' => 'optifleet', 'name' => 'OptiFleet', 'frontend_url' => 'https://fleet.example.test']);
        $this->radar = $this->makeApplication(['application_code' => 'optiradar', 'name' => 'OptiRadar', 'frontend_url' => 'https://radar.example.test']);
        $this->tenant = $this->makeTenant();
        $this->user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($this->user, $this->tenant);
        [$this->token] = $this->issueServiceAccountTokenFor($this->entry->id);
    }

    private function subscribe(Application $application, Tenant $tenant, bool $withAccess = true): void
    {
        $tenant->applications()->attach($application->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        if ($withAccess) {
            $this->grantApplicationAccess($this->user, $tenant, $application);
        }
    }

    private function ask(?Tenant $tenant = null, ?User $user = null, ?string $token = null): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.($token ?? $this->token))
            ->postJson(self::ENDPOINT, ['user_id' => ($user ?? $this->user)->id, 'tenant_id' => ($tenant ?? $this->tenant)->id]);
    }

    public function test_lists_only_products_the_tenant_subscribes_to_and_the_user_was_assigned(): void
    {
        $this->subscribe($this->entry, $this->tenant);
        $this->subscribe($this->fleet, $this->tenant);
        $this->subscribe($this->radar, $this->tenant, withAccess: false);   // subscribed but not assigned
        $otherTenant = $this->makeTenant();
        $otherTenant->applications()->attach($this->radar->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);

        $response = $this->ask()->assertOk()
            ->assertJsonPath('data.schema_version', '1.0')
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.deny_reason', null)
            ->assertJsonPath('data.tenant.id', $this->tenant->id);

        $products = collect($response->json('data.products'))->keyBy('code');
        $this->assertSame(['optiaccounting', 'optifleet'], $products->keys()->sort()->values()->all());
        $this->assertTrue($products['optiaccounting']['current']);
        $this->assertFalse($products['optifleet']['current']);
        $this->assertSame('https://fleet.example.test', $products['optifleet']['launch_url']);
    }

    public function test_the_governance_portal_is_never_listed(): void
    {
        $cgo = Application::query()->where('application_code', 'cgo')->firstOrFail();
        $this->subscribe($this->entry, $this->tenant);
        $this->tenant->applications()->attach($cgo->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $this->grantApplicationAccess($this->user, $this->tenant, $cgo);

        $codes = collect($this->ask()->assertOk()->json('data.products'))->pluck('code');

        $this->assertFalse($codes->contains('cgo'));
    }

    public function test_revoked_access_is_reported_so_the_product_can_end_its_session(): void
    {
        $this->subscribe($this->entry, $this->tenant);
        $this->subscribe($this->fleet, $this->tenant);
        UserApplicationAccess::query()->where('user_id', $this->user->id)->where('application_id', $this->entry->id)->delete();

        $this->ask()->assertOk()
            ->assertJsonPath('data.allowed', false)
            ->assertJsonPath('data.deny_reason', 'application_access_revoked')
            ->assertJsonPath('data.products', [])
            ->assertJsonPath('data.tenant', null);
    }

    public function test_every_loss_of_access_is_reported_with_its_reason(): void
    {
        $this->subscribe($this->entry, $this->tenant);

        $this->tenant->applications()->updateExistingPivot($this->entry->id, ['status' => 'SUSPENDED']);
        $this->ask()->assertJsonPath('data.deny_reason', 'application_not_assigned');
        $this->tenant->applications()->updateExistingPivot($this->entry->id, ['status' => 'ACTIVE']);

        $this->user->update(['status' => User::STATUS_SUSPENDED]);
        $this->ask()->assertJsonPath('data.deny_reason', 'user_suspended')->assertJsonPath('data.allowed', false);
        $this->user->update(['status' => User::STATUS_ACTIVE]);

        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
        $this->ask()->assertJsonPath('data.deny_reason', 'tenant_suspended');
    }

    public function test_another_tenants_products_do_not_leak(): void
    {
        $this->subscribe($this->entry, $this->tenant);
        $otherTenant = $this->makeTenant();
        $this->attachUserToTenant($this->user, $otherTenant);
        $this->subscribe($this->radar, $otherTenant);

        $codes = collect($this->ask()->assertOk()->json('data.products'))->pluck('code');
        $this->assertSame(['optiaccounting'], $codes->all());

        $codesOther = collect($this->ask($otherTenant)->json('data.products'));
        $this->assertSame('application_access_revoked', $this->ask($otherTenant)->json('data.deny_reason'));
        $this->assertSame([], $codesOther->all());
    }

    public function test_binding_and_authentication_rules(): void
    {
        $this->subscribe($this->entry, $this->tenant);

        [$unbound] = $this->issueServiceAccountTokenFor(null);
        $this->ask(null, null, $unbound)->assertStatus(403)->assertJsonPath('error.code', 'SERVICE_ACCOUNT_NOT_BOUND');

        [$pinned] = $this->issueServiceAccountTokenFor($this->entry->id, ['authorization.check'], $this->makeTenant()->id);
        $this->ask(null, null, $pinned)->assertStatus(403)->assertJsonPath('error.code', 'TENANT_MISMATCH');

        $this->flushHeaders();
        $this->postJson(self::ENDPOINT, ['user_id' => $this->user->id, 'tenant_id' => $this->tenant->id])->assertStatus(401);
    }

    public function test_unknown_user_or_tenant_gets_a_deny_without_details(): void
    {
        $this->subscribe($this->entry, $this->tenant);
        $ghost = new User;
        $ghost->id = (string) Str::uuid();

        $this->ask(null, $ghost)->assertOk()->assertJsonPath('data.allowed', false)->assertJsonPath('data.deny_reason', 'user_not_found')->assertJsonPath('data.products', []);
    }
}
