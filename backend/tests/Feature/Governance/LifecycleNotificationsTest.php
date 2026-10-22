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
}
