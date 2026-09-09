<?php

namespace Tests\Feature\Orchestration;

use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\CreatesOrchestrationFixtures;
use Tests\TestCase;

class NotificationManagementTest extends TestCase
{
    use CreatesGovernanceFixtures, CreatesOrchestrationFixtures, RefreshDatabase;

    private function makeTemplate(string $body = 'Hello {{data.name}}.'): string
    {
        $this->actingAsOrchestrationRole('NOTIFICATION_ADMIN');

        $id = $this->postJson('/api/v1/notification-templates', [
            'template_code' => 'NT-'.uniqid(),
            'name' => 'Test Template',
            'channel' => NotificationTemplate::CHANNEL_IN_APP,
            'body_template' => $body,
        ])->assertStatus(201)->json('data.id');

        $this->putJson("/api/v1/notification-templates/{$id}", ['status' => NotificationTemplate::STATUS_ACTIVE])->assertStatus(200);

        return $id;
    }

    public function test_direct_send_renders_template_and_queues_delivery(): void
    {
        Bus::fake();
        $templateId = $this->makeTemplate();
        $recipient = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $response = $this->postJson('/api/v1/notifications/send', [
            'template_code' => NotificationTemplate::find($templateId)->template_code,
            'recipient_user_ids' => [$recipient->id],
            'context' => ['data' => ['name' => 'Alice']],
        ])->assertStatus(201);

        $body = $response->json('data.0.body');
        $this->assertSame('Hello Alice.', $body);
        Bus::assertDispatched(\App\Jobs\SendNotification::class);
    }

    public function test_direct_send_requires_elevated_permission(): void
    {
        $templateId = $this->makeTemplate();
        $recipient = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->seedOrchestrationBaseline();
        $noPermUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        \Laravel\Sanctum\Sanctum::actingAs($noPermUser);

        $this->postJson('/api/v1/notifications/send', [
            'template_code' => NotificationTemplate::find($templateId)->template_code,
            'recipient_user_ids' => [$recipient->id],
        ])->assertStatus(403);
    }

    public function test_recipient_outside_tenant_is_rejected(): void
    {
        $templateId = $this->makeTemplate();
        $tenant = $this->makeTenant();
        $outsider = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        // outsider is never attached to $tenant.

        $this->postJson('/api/v1/notifications/send', [
            'template_code' => NotificationTemplate::find($templateId)->template_code,
            'recipient_user_ids' => [$outsider->id],
            'tenant_id' => $tenant->id,
        ])->assertStatus(422);
    }

    public function test_template_rendering_never_executes_embedded_markup(): void
    {
        $templateId = $this->makeTemplate('Value: {{data.payload}}');
        $recipient = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $response = $this->postJson('/api/v1/notifications/send', [
            'template_code' => NotificationTemplate::find($templateId)->template_code,
            'recipient_user_ids' => [$recipient->id],
            'context' => ['data' => ['payload' => '<script>alert(1)</script>']],
        ])->assertStatus(201);

        $this->assertStringContainsString('<script>alert(1)</script>', $response->json('data.0.body'));
    }

    public function test_retry_only_allowed_from_failed_status(): void
    {
        $templateId = $this->makeTemplate();
        $recipient = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $id = $this->postJson('/api/v1/notifications/send', [
            'template_code' => NotificationTemplate::find($templateId)->template_code,
            'recipient_user_ids' => [$recipient->id],
        ])->json('data.0.id');

        // Still QUEUED - not eligible for retry.
        $this->postJson("/api/v1/notifications/{$id}/retry")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'NOTIFICATION_NOT_RETRYABLE');
    }
}
