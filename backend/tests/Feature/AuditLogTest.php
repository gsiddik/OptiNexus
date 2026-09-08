<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    public function test_denied_admin_api_request_generates_an_audit_event(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/customers', ['customer_code' => 'X', 'legal_name' => 'X'])->assertStatus(403);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'authorization.denied',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_customer_creation_generates_an_audit_event(): void
    {
        $this->actingAsSuperAdmin();

        $id = $this->postJson('/api/v1/customers', ['customer_code' => 'AUDITME', 'legal_name' => 'Audit Me'])
            ->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customer.created',
            'resource_type' => 'Customer',
            'resource_id' => $id,
        ]);
    }

    public function test_audit_logs_are_readable_through_the_admin_api(): void
    {
        $this->actingAsSuperAdmin();
        $this->postJson('/api/v1/customers', ['customer_code' => 'AUDITLIST', 'legal_name' => 'Audit List']);

        $this->getJson('/api/v1/audit-logs')
            ->assertStatus(200)
            ->assertJsonFragment(['action' => 'customer.created']);
    }

    public function test_audit_log_cannot_be_modified_or_deleted_via_eloquent(): void
    {
        $log = AuditLog::create(['action' => 'test.action']);

        $this->expectException(RuntimeException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_audit_log_delete_is_blocked(): void
    {
        $log = AuditLog::create(['action' => 'test.action']);

        $this->expectException(RuntimeException::class);
        $log->delete();
    }

    public function test_there_is_no_update_or_delete_route_for_audit_logs(): void
    {
        $this->actingAsSuperAdmin();
        $log = AuditLog::create(['action' => 'test.action']);

        $this->putJson("/api/v1/audit-logs/{$log->id}", ['action' => 'tampered'])->assertStatus(405);
        $this->deleteJson("/api/v1/audit-logs/{$log->id}")->assertStatus(405);
    }

    public function test_external_audit_event_submission_requires_a_trusted_service_account(): void
    {
        $tenant = $this->makeTenant();

        $this->postJson('/api/v1/audit-events', ['action' => 'vehicle.updated', 'tenant_id' => $tenant->id])
            ->assertStatus(401);
    }

    public function test_external_audit_event_records_the_authenticated_client_as_the_source_not_the_body(): void
    {
        $application = $this->makeApplication(['application_code' => 'optifleet']);
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient('optifleet-svc');
        ServiceAccount::create([
            'application_id' => $application->id,
            'oauth_client_id' => $client->id,
            'name' => 'optifleet-svc',
            'status' => ServiceAccount::STATUS_ACTIVE,
        ]);

        $token = $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'scope' => 'audit.write',
        ])->json('access_token');

        // Even if the body tries to claim a different application_id, the
        // server derives it from the authenticated client, not the payload.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/audit-events', [
                'action' => 'vehicle.updated',
                'actor_identity' => 'spoofed-actor',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'id' => $response->json('data.id'),
            'application_id' => $application->id,
            'source' => 'optifleet',
        ]);
    }
}
