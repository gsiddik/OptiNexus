<?php

namespace Tests\Feature\Governance;

use App\Jobs\SendAccountCredential;
use App\Models\Notification;
use App\Models\User;
use App\Services\Commercial\SubscriptionApplicationService;
use App\Services\Commercial\SubscriptionLifecycleService;
use App\Services\Ecosystem\AccountActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class LifecycleNotificationsTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    public function test_reactivated_tenant_user_must_replace_the_generated_password(): void
    {
        Queue::fake();
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $user = User::factory()->create(['status' => 'SUSPENDED', 'password' => 'Original password 2026']);
        $user->tenantMemberships()->create(['tenant_id' => $tenant->id, 'status' => 'ACTIVE']);
        $password = $this->postJson("/api/v1/users/$user->id/activate")->assertOk()
            ->assertJsonPath('data.status', 'INVITED')->assertJsonPath('data.activation_state', 'PASSWORD_CHANGE_REQUIRED')->json('data.generated_password');
        $user->refresh();
        $this->assertFalse(Hash::check('Original password 2026', $user->password));
        $this->assertTrue(app(AccountActivationService::class)->matchesTemporaryPassword($user, $password));
        Queue::assertPushed(SendAccountCredential::class);
        $result = app(AccountActivationService::class)->changePassword($user->id, $user->temporary_password_issued_at->timestamp, $password, 'Chosen password 2026!', request());
        $this->assertTrue($result['activated']);
        $this->assertSame('ACTIVE', $user->fresh()->status);
    }

    public function test_email_change_resets_activation_and_old_tokens_without_leaking_password(): void
    {
        Queue::fake();
        $this->actingAsSuperAdmin();
        $tenant = $this->makeTenant();
        $user = User::factory()->create(['status' => 'ACTIVE', 'email' => 'old@example.test']);
        $user->tenantMemberships()->create(['tenant_id' => $tenant->id, 'status' => 'ACTIVE']);
        $user->createToken('old session');
        $response = $this->putJson("/api/v1/users/$user->id", ['email' => 'new@example.test'])->assertOk()
            ->assertJsonPath('data.status', 'INVITED')->assertJsonPath('data.email_verified_at', null)
            ->assertJsonPath('data.activation_state', 'PASSWORD_CHANGE_REQUIRED');
        $this->assertArrayNotHasKey('generated_password', $response->json('data'));
        $this->assertSame(0, $user->tokens()->count());
        Queue::assertPushed(SendAccountCredential::class, fn ($job) => $job->userId === $user->id && $job->email === 'new@example.test');
        $this->assertTrue($user->fresh()->password_change_required);
        $job = Queue::pushed(SendAccountCredential::class)->first();
        $plain = (new \ReflectionProperty($job, 'password'))->getValue($job);
        $fresh = $user->fresh();
        $result = app(AccountActivationService::class)->changePassword($fresh->id, $fresh->temporary_password_issued_at->timestamp, $plain, 'Chosen replacement 2026!', request());
        $this->assertTrue($result['activated']);
        $this->assertSame('EMAIL_CREDENTIAL', $user->fresh()->email_verification_method);
    }

    public function test_derived_applications_use_the_union_and_reject_unrelated_selection(): void
    {
        Queue::fake();
        $this->seedCommercialBaseline();
        $tenant = $this->makeTenant();
        $app = $this->makeApplication();
        $one = $this->makeActiveApplicationSubscription($tenant, $app);
        $two = $this->makeActiveApplicationSubscription($tenant, $app);
        $service = app(SubscriptionApplicationService::class);
        $service->sync($tenant);
        $this->assertSame([$app->id], $tenant->applications()->pluck('applications.id')->all());
        app(SubscriptionLifecycleService::class)->terminate($one);
        $this->assertSame([$app->id], $tenant->applications()->pluck('applications.id')->all());
        app(SubscriptionLifecycleService::class)->terminate($two);
        $this->assertSame([], $tenant->applications()->pluck('applications.id')->all());
    }

    public function test_operational_email_is_scoped_and_duplicate_status_save_sends_nothing(): void
    {
        Queue::fake();
        $this->seedGovernanceBaseline();
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $admin = User::factory()->create(['status' => 'ACTIVE']);
        $stranger = User::factory()->create(['status' => 'ACTIVE']);
        $admin->tenantMemberships()->create(['tenant_id' => $tenant->id, 'status' => 'ACTIVE', 'is_tenant_admin' => true]);
        $stranger->tenantMemberships()->create(['tenant_id' => $other->id, 'status' => 'ACTIVE', 'is_tenant_admin' => true]);
        $tenant->update(['status' => 'SUSPENDED']);
        $this->assertSame(1, Notification::where('recipient_user_id', $admin->id)->where('channel', 'EMAIL')->count());
        $this->assertSame(0, Notification::where('recipient_user_id', $stranger->id)->count());
        $tenant->update(['status' => 'SUSPENDED']);
        $this->assertSame(1, Notification::where('recipient_user_id', $admin->id)->count());
    }

    public function test_pricing_change_notifies_only_the_affected_registered_tenant_admin(): void
    {
        Queue::fake();
        $this->seedCommercialBaseline();
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $admin = User::factory()->create(['status' => 'ACTIVE']);
        $admin->tenantMemberships()->create(['tenant_id' => $tenant->id, 'status' => 'ACTIVE', 'is_tenant_admin' => true]);
        $subscription = $this->makeActiveApplicationSubscription($tenant, $this->makeApplication());
        $this->makeFlatPrice($subscription->plan, ['tenant_id' => $other->id]);
        $this->assertSame(0, Notification::where('recipient_user_id', $admin->id)->count());
        $this->makeFlatPrice($subscription->plan, ['unit_amount' => '250000.00']);
        $this->assertSame(1, Notification::where('recipient_user_id', $admin->id)->where('subject', 'Subscription pricing changed')->count());
    }

    public function test_subscription_selection_assigns_only_included_apps_and_rejects_unrelated_apps(): void
    {
        Queue::fake();
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $customer = $this->makeCustomer();
        $tenant = $this->makeTenant($customer);
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $one = $this->makeApplication();
        $two = $this->makeApplication();
        foreach ([$one, $two] as $application) {
            $product->applications()->attach($application->id, ['id' => (string) Str::uuid()]);
        }
        $data = ['customer_id' => $customer->id, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id];
        $this->postJson('/api/v1/subscriptions', [...$data, 'application_ids' => [$one->id]])->assertCreated();
        $this->assertSame([$one->id], $tenant->applications()->pluck('applications.id')->all());
        $other = $this->makeApplication();
        $this->postJson('/api/v1/subscriptions', [...$data, 'application_ids' => [$other->id]])->assertStatus(422);
        $this->assertSame(1, $tenant->subscriptions()->count());
        $this->assertSame([$one->id], $tenant->applications()->pluck('applications.id')->all());
    }
}
