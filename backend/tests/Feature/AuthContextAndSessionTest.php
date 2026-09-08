<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class AuthContextAndSessionTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    public function test_login_with_valid_credentials_issues_a_token(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password'), 'status' => User::STATUS_ACTIVE]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertStatus(200);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password'), 'status' => User::STATUS_ACTIVE]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password'), 'status' => User::STATUS_SUSPENDED]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_token_is_revoked_on_logout(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password'), 'status' => User::STATUS_ACTIVE]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->json('data.token');

        $tokenId = explode('|', $token, 2)[0];
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenId]);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/logout')->assertStatus(200);

        // Asserted at the persistence layer rather than via a second
        // simulated request: Laravel's AuthManager caches the resolved
        // guard/user for the lifetime of the test process, so a follow-up
        // in-process request can still see the pre-logout auth state even
        // though the token row is gone (verified separately end-to-end).
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_context_reflects_tenant_roles_and_permissions(): void
    {
        $this->seedGovernanceBaseline();
        $tenant = $this->makeTenant();
        $role = $this->makeTenantRole($tenant, ['cgo.user.view']);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->attachUserToTenant($user, $tenant, $role);

        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson("/api/v1/auth/context?tenant_id={$tenant->id}")
            ->assertStatus(200)
            ->assertJsonFragment(['permission_key' => 'cgo.user.view', 'scope' => 'TENANT'])
            ->assertJsonFragment(['code' => $role->code]);
    }

    public function test_context_for_a_tenant_the_user_does_not_belong_to_is_denied(): void
    {
        $this->seedGovernanceBaseline();
        $tenant = $this->makeTenant();
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson("/api/v1/auth/context?tenant_id={$tenant->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
    }
}
