<?php

namespace Tests\Feature\Orchestration;

use App\Jobs\SendNotification;
use App\Models\AuditLog;
use App\Models\EmailSuppression;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\NotificationDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\CreatesOrchestrationFixtures;
use Tests\TestCase;

/** Multipart delivery of operational e-mail and the Postmark bounce/complaint suppression loop. */
class EmailSuppressionTest extends TestCase
{
    use CreatesGovernanceFixtures, CreatesOrchestrationFixtures, RefreshDatabase;

    private const HOOK = '/api/v1/webhooks/postmark';

    protected function setUp(): void
    {
        parent::setUp();
        config(['notifications.postmark_webhook' => ['username' => 'postmark', 'password' => 'hook-secret-for-tests']]);
    }

    private function sentMessages()
    {
        return app('mailer')->getSymfonyTransport()->messages();
    }

    private function queuedNotice(string $email): Notification
    {
        Queue::fake();
        $this->seedGovernanceBaseline();
        $tenant = $this->makeTenant(null, ['name' => 'Toko Sinar']);
        $admin = User::factory()->create(['status' => 'ACTIVE', 'email' => $email]);
        $admin->tenantMemberships()->create(['tenant_id' => $tenant->id, 'status' => 'ACTIVE', 'is_tenant_admin' => true]);
        $tenant->update(['status' => 'SUSPENDED']);

        return Notification::where('recipient_user_id', $admin->id)->sole();
    }

    private function hook(array $payload, string $password = 'hook-secret-for-tests')
    {
        return $this->withHeaders(['Authorization' => 'Basic '.base64_encode('postmark:'.$password)])->postJson(self::HOOK, $payload);
    }

    public function test_operational_notice_is_sent_as_html_plus_plain_text(): void
    {
        $notification = $this->queuedNotice('admin@toko.test');

        (new SendNotification($notification->id))->handle(app(NotificationDispatchService::class));

        $this->assertSame('SENT', $notification->refresh()->status);
        $email = $this->sentMessages()->sole()->getOriginalMessage();
        $this->assertSame('admin@toko.test', $email->getTo()[0]->getAddress());
        $this->assertSame($notification->subject, $email->getSubject());
        $this->assertStringContainsString('<table', (string) $email->getHtmlBody());
        $this->assertStringContainsString('Tenant Toko Sinar kini berstatus', (string) $email->getTextBody());
    }

    public function test_suppressed_address_is_cancelled_without_sending_or_retrying(): void
    {
        $notification = $this->queuedNotice('Bounced@Toko.test');
        EmailSuppression::create(['email' => 'bounced@toko.test', 'reason' => 'HARD_BOUNCE', 'provider' => 'postmark', 'suppressed_at' => now()]);

        (new SendNotification($notification->id))->handle(app(NotificationDispatchService::class));

        $notification->refresh();
        $this->assertSame('CANCELLED', $notification->status);
        $this->assertStringStartsWith('EMAIL_SUPPRESSED', $notification->last_error_message);
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_webhook_is_absent_until_configured_and_rejects_wrong_credentials(): void
    {
        $this->hook(['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'x@y.test'], 'wrong')->assertStatus(401);
        $this->postJson(self::HOOK, ['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'x@y.test'])->assertStatus(401);

        config(['notifications.postmark_webhook' => ['username' => null, 'password' => null]]);
        $this->hook(['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'x@y.test'])->assertStatus(404);

        $this->assertSame(0, EmailSuppression::count());
    }

    public function test_hard_bounce_and_spam_complaint_suppress_soft_bounce_does_not_and_replays_are_harmless(): void
    {
        $this->hook(['RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Inactive' => false, 'Email' => 'soft@y.test'])
            ->assertOk()->assertJsonPath('data.result', 'ignored');
        $bounce = ['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Inactive' => true, 'Email' => 'Hard@Y.test', 'MessageID' => 'pm-1', 'Description' => 'Unknown user'];
        $this->hook($bounce)->assertOk()->assertJsonPath('data.result', 'suppressed');
        $this->hook($bounce)->assertOk()->assertJsonPath('data.result', 'suppressed');
        $this->hook(['RecordType' => 'SpamComplaint', 'Email' => 'spam@y.test', 'MessageID' => 'pm-2'])->assertOk();
        $this->hook(['RecordType' => 'Delivery', 'Recipient' => 'ok@y.test'])->assertOk()->assertJsonPath('data.result', 'ignored');

        $this->assertSame(2, EmailSuppression::count());
        $this->assertTrue(EmailSuppression::blocks('hard@y.test'));
        $this->assertSame('SPAM_COMPLAINT', EmailSuppression::where('email', 'spam@y.test')->value('reason'));
        $this->assertFalse(EmailSuppression::blocks('soft@y.test'));
        $this->assertSame(2, AuditLog::where('action', 'email_suppression.created')->count());
    }

    public function test_provider_reactivation_releases_and_a_new_bounce_suppresses_again(): void
    {
        $this->hook(['RecordType' => 'SubscriptionChange', 'Recipient' => 'a@y.test', 'SuppressSending' => true, 'SuppressionReason' => 'ManualSuppression'])
            ->assertOk()->assertJsonPath('data.result', 'suppressed');
        $this->assertSame('MANUAL_SUPPRESSION', EmailSuppression::where('email', 'a@y.test')->value('reason'));

        $this->hook(['RecordType' => 'SubscriptionChange', 'Recipient' => 'a@y.test', 'SuppressSending' => false])
            ->assertOk()->assertJsonPath('data.result', 'released');
        $this->assertFalse(EmailSuppression::blocks('a@y.test'));

        $this->hook(['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'a@y.test'])->assertOk();
        $row = EmailSuppression::where('email', 'a@y.test')->sole();
        $this->assertTrue($row->isActive());
        $this->assertSame('HARD_BOUNCE', $row->reason);
    }

    public function test_operators_list_and_release_suppressions_with_notification_permissions_only(): void
    {
        $this->seedOrchestrationBaseline();
        $row = EmailSuppression::create(['email' => 'hard@y.test', 'reason' => 'HARD_BOUNCE', 'provider' => 'postmark', 'suppressed_at' => now()]);

        $this->actingAsOperatorWith([]);
        $this->getJson('/api/v1/email-suppressions')->assertStatus(403);

        $this->actingAsOperatorWith(['cgo.notification.delivery.view']);
        $this->getJson('/api/v1/email-suppressions?state=active&search=HARD')->assertOk()
            ->assertJsonPath('data.0.email', 'hard@y.test')->assertJsonPath('data.0.active', true);
        $this->postJson("/api/v1/email-suppressions/{$row->id}/release")->assertStatus(403);

        $operator = $this->actingAsOperatorWith(['cgo.notification.delivery.view', 'cgo.notification.delivery.retry']);
        $this->postJson("/api/v1/email-suppressions/{$row->id}/release")->assertOk()
            ->assertJsonPath('data.active', false)->assertJsonPath('data.released_by.id', $operator->id);
        $this->postJson("/api/v1/email-suppressions/{$row->id}/release")->assertStatus(409)
            ->assertJsonPath('error.code', 'EMAIL_SUPPRESSION_NOT_ACTIVE');
        $this->getJson('/api/v1/email-suppressions?state=active')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'email_suppression.released', 'resource_id' => $row->id]);
    }
}
