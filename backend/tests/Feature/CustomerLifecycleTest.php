<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class CustomerLifecycleTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/customers')->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_super_admin_can_create_and_read_a_customer(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/v1/customers', [
            'customer_code' => 'ACME2',
            'legal_name' => 'Acme Two Pte Ltd',
            'email' => 'ops@acme2.example',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', Customer::STATUS_ACTIVE);

        $id = $response->json('data.id');

        $this->getJson("/api/v1/customers/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.customer_code', 'ACME2');
    }

    public function test_duplicate_customer_code_is_rejected(): void
    {
        $this->actingAsSuperAdmin();
        $this->makeCustomer(['customer_code' => 'DUPCODE']);

        $this->postJson('/api/v1/customers', ['customer_code' => 'DUPCODE', 'legal_name' => 'X'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_lifecycle_transitions_follow_allowed_paths(): void
    {
        $this->actingAsSuperAdmin();
        $customer = $this->makeCustomer(['status' => Customer::STATUS_ACTIVE]);

        $this->postJson("/api/v1/customers/{$customer->id}/suspend")
            ->assertStatus(200)
            ->assertJsonPath('data.status', Customer::STATUS_SUSPENDED);

        // Cannot terminate->active directly via activate from a non-suspended state twice
        $this->postJson("/api/v1/customers/{$customer->id}/terminate")
            ->assertStatus(200)
            ->assertJsonPath('data.status', Customer::STATUS_TERMINATED);

        // A terminated customer cannot be re-activated (invalid transition).
        $this->postJson("/api/v1/customers/{$customer->id}/activate")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

        $this->postJson("/api/v1/customers/{$customer->id}/archive")
            ->assertStatus(200)
            ->assertJsonPath('data.status', Customer::STATUS_ARCHIVED);
    }

    public function test_user_without_permission_cannot_create_customer(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/customers', ['customer_code' => 'NOPE', 'legal_name' => 'Nope'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }
}
