<?php

namespace Tests\Feature;

use App\Models\Entitlement;
use App\Models\User;
use App\Services\Seats\SeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class GovernanceWorkflowTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCommercialBaseline();
    }

    public function test_operator_can_set_and_replace_seats_without_creating_conflicting_overrides(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $app = $this->makeApplication(['metadata' => ['manifest' => ['entitlement_limit_keys' => ['user_limit']]]]);
        $tenant->applications()->attach($app->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $url = "/api/v1/tenants/{$tenant->id}/seats/{$app->id}";
        foreach ([5, 12] as $limit) {
            $this->putJson($url, ['limit' => $limit, 'reason' => 'Purchased additional seats'])->assertOk()->assertJsonPath('data.0.limit', $limit);
        }
        $this->assertSame(1, Entitlement::where('tenant_id', $tenant->id)->where('status', 'ACTIVE')->count());
        $this->assertSame(1, Entitlement::where('tenant_id', $tenant->id)->where('status', 'REVOKED')->count());
        $this->assertSame(12, app(SeatService::class)->status($tenant, $app)->limit);
    }

    public function test_seat_changes_require_override_permission_and_assigned_controlled_application(): void
    {
        $tenant = $this->makeTenant();
        $app = $this->makeApplication();
        $this->actingAsOperatorWith(['cgo.tenant.view']);
        $url = "/api/v1/tenants/{$tenant->id}/seats/{$app->id}";
        $data = ['limit' => 5, 'reason' => 'Purchased additional seats'];
        $this->putJson($url, $data)->assertForbidden();
        $this->actingAsSuperAdmin();
        $this->putJson($url, $data)->assertUnprocessable()->assertJsonPath('error.code', 'SEAT_APPLICATION_INVALID');
        $this->putJson($url, ['limit' => -1, 'reason' => 'short'])->assertUnprocessable();
    }

    public function test_customer_contact_details_validate_and_persist(): void
    {
        $this->actingAsSuperAdmin();
        $customer = $this->makeCustomer();
        $url = "/api/v1/customers/{$customer->id}";
        $this->putJson($url, ['email' => 'invalid', 'phone' => '12345', 'industry' => 'Invalid'])->assertUnprocessable();
        $this->putJson($url, ['email' => null])->assertUnprocessable();
        $this->putJson($url, ['email' => 'contact@example.test', 'business_name' => 'Acme', 'tax_id' => 'TAX-1', 'industry' => 'Logistic', 'phone' => '+628123456789'])->assertOk();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'email' => 'contact@example.test', 'industry' => 'Logistic', 'phone' => '+628123456789']);
        $this->putJson($url, ['phone' => '0123456789012345'])->assertUnprocessable();
    }

    public function test_activation_reveals_a_generated_password_once_and_stores_only_its_hash(): void
    {
        Queue::fake();
        $this->actingAsSuperAdmin();
        $user = User::factory()->create(['status' => User::STATUS_INVITED]);
        $response = $this->postJson("/api/v1/users/{$user->id}/activate")->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $password = $response->json('data.generated_password');
        $this->assertGreaterThanOrEqual(12, strlen($password));
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson("/api/v1/users/{$user->id}")->assertJsonMissingPath('data.generated_password');
    }

    public function test_existing_account_choices_are_scoped_to_tenants_customer(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $user = User::factory()->create(['status' => 'ACTIVE']);
        $this->attachUserToTenant($user, $this->makeTenant($tenant->customer));
        $foreign = User::factory()->create(['status' => 'ACTIVE']);
        $this->attachUserToTenant($foreign, $this->makeTenant());
        $this->getJson("/api/v1/ecosystem/tenants/{$tenant->id}/admin-accounts/account-options")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $user->id)->assertJsonMissingPath('data.0.password');
    }

    public function test_linked_account_email_changes_require_update_permission_and_reverification(): void
    {
        Queue::fake();
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $app = $this->makeApplication();
        $tenant->applications()->attach($app->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $user = User::factory()->create(['status' => 'ACTIVE', 'email_verified_at' => now()]);
        $this->attachUserToTenant($user, $tenant);
        $payload = ['user_id' => $user->id, 'confirm_email' => 'changed@example.test', 'application_ids' => [$app->id]];
        $url = "/api/v1/ecosystem/tenants/{$tenant->id}/admin-accounts";
        $this->actingAsOperatorWith(['cgo.tenant.admin.assign', 'cgo.user.tenant.assign', 'cgo.user.application.assign']);
        $this->postJson($url, $payload)->assertForbidden();
        $this->assertNotSame('changed@example.test', $user->fresh()->email);
        $this->actingAsSuperAdmin();
        $this->postJson($url, $payload)->assertCreated();
        $this->assertSame('changed@example.test', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_existing_tenant_admin_can_reset_password_into_managed_activation(): void
    {
        Queue::fake();
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $user = User::factory()->create(['status' => 'ACTIVE', 'email_verified_at' => now()]);
        $this->attachUserToTenant($user, $tenant, isTenantAdmin: true);
        $response = $this->postJson("/api/v1/ecosystem/tenants/{$tenant->id}/admin-accounts/{$user->id}/temporary-credential")->assertOk();
        $password = str_replace('-', '', $response->json('data.temporary_credential.password'));
        $this->assertGreaterThanOrEqual(12, strlen($password));
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertTrue($user->fresh()->isInManagedActivation());
        $this->assertSame(User::STATUS_INVITED, $user->fresh()->status);
    }
}
