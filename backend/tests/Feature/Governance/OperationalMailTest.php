<?php

namespace Tests\Feature\Governance;

use App\Jobs\SendNotification;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notification\Operational\OperationalMailComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

/** Operational e-mails a-f (docs/OPERATIONAL_EMAIL.md): who receives them, what they say, and the invoice due-date follow-up. */
class OperationalMailTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['notifications.mail_locale' => 'id', 'notifications.console_url' => 'https://nexus.example.test']);
    }

    private function tenantAdmin(Tenant $tenant): User
    {
        $admin = User::factory()->create(['status' => 'ACTIVE']);
        $admin->tenantMemberships()->create(['tenant_id' => $tenant->id, 'status' => 'ACTIVE', 'is_tenant_admin' => true]);

        return $admin;
    }

    private function mailsFor(User $user)
    {
        return Notification::where('recipient_user_id', $user->id)->where('channel', 'EMAIL')->orderBy('created_at')->get();
    }

    public function test_customer_suspension_and_termination_reach_only_the_admins_of_its_tenants(): void
    {
        $this->seedGovernanceBaseline();
        $customer = $this->makeCustomer(['legal_name' => 'PT Maju Jaya']);
        $admin = $this->tenantAdmin($this->makeTenant($customer));
        $stranger = $this->tenantAdmin($this->makeTenant());

        $customer->update(['status' => 'SUSPENDED']);
        $customer->update(['status' => 'TERMINATED']);

        $mails = $this->mailsFor($admin);
        $this->assertCount(2, $mails);
        $this->assertSame('Customer PT Maju Jaya kini Ditangguhkan (SUSPENDED)', $mails[0]->subject);
        $this->assertStringContainsString('Diakhiri (TERMINATED)', $mails[1]->subject);
        $this->assertStringContainsString('Status sebelumnya: Ditangguhkan (SUSPENDED)', $mails[1]->body);
        $this->assertStringContainsString('https://nexus.example.test/customers/'.$customer->id, $mails[0]->body_html);
        $this->assertCount(0, $this->mailsFor($stranger));
        Queue::assertPushed(SendNotification::class, 2);
    }

    public function test_user_suspension_and_disabling_reach_the_admins_of_that_users_tenants_only(): void
    {
        $this->seedGovernanceBaseline();
        $tenant = $this->makeTenant();
        $admin = $this->tenantAdmin($tenant);
        $stranger = $this->tenantAdmin($this->makeTenant());
        $user = User::factory()->create(['status' => 'ACTIVE', 'name' => 'Budi', 'email' => 'budi@example.test']);
        $user->tenantMemberships()->create(['tenant_id' => $tenant->id, 'status' => 'ACTIVE', 'is_tenant_admin' => false]);

        $user->update(['status' => 'SUSPENDED']);
        $user->update(['status' => 'DISABLED']);
        $user->update(['status' => 'DISABLED']);

        $mails = $this->mailsFor($admin);
        $this->assertCount(2, $mails);
        $this->assertSame('Pengguna Budi kini Ditangguhkan (SUSPENDED)', $mails[0]->subject);
        $this->assertSame('Pengguna Budi kini Dinonaktifkan (DISABLED)', $mails[1]->subject);
        $this->assertStringContainsString('budi@example.test', $mails[1]->body);
        $this->assertCount(0, $this->mailsFor($stranger));
    }

    public function test_tenant_notice_is_rendered_as_html_and_text_with_escaped_values(): void
    {
        $this->seedGovernanceBaseline();
        $tenant = $this->makeTenant(null, ['name' => 'Toko <b>A&B</b>']);
        $admin = $this->tenantAdmin($tenant);

        $tenant->update(['status' => 'TERMINATED']);

        $mail = $this->mailsFor($admin)->sole();
        $this->assertStringContainsString('Toko &lt;b&gt;A&amp;B&lt;/b&gt;', $mail->body_html);
        $this->assertStringNotContainsString('<b>A&B</b>', $mail->body_html);
        $this->assertStringContainsString('Toko <b>A&B</b>', $mail->body);
        $this->assertStringContainsString('Halo '.$admin->name, $mail->body);
        $this->assertStringContainsString('https://nexus.example.test/tenants/'.$tenant->id, $mail->body);
    }

    public function test_issued_invoice_and_each_later_status_change_reach_the_tenant_admin(): void
    {
        [$tenant, $invoiceId] = $this->issuedInvoice();
        $admin = $this->tenantAdmin($tenant);
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $this->postJson("/api/v1/invoices/{$invoiceId}/mark-partially-paid", ['amount' => '500000.00'])->assertOk();
        $this->postJson("/api/v1/invoices/{$invoiceId}/mark-paid")->assertOk();

        $subjects = $this->mailsFor($admin)->pluck('subject')->all();
        $invoice = Invoice::findOrFail($invoiceId);
        $this->assertSame([
            "Status invoice {$invoice->invoice_number}: Dibayar sebagian (PARTIALLY_PAID)",
            "Status invoice {$invoice->invoice_number}: Lunas (PAID)",
        ], $subjects);
        $paid = $this->mailsFor($admin)->last();
        $this->assertStringContainsString('Sisa tagihan: IDR 0,00', $paid->body);
        $this->assertStringContainsString('Tidak ada tindakan lanjutan yang diperlukan.', $paid->body);
    }

    public function test_newly_issued_invoice_asks_the_tenant_admin_to_follow_up(): void
    {
        [$tenant, $billingId] = $this->finalizedBillingFor();
        $admin = $this->tenantAdmin($tenant);
        $stranger = $this->tenantAdmin($this->makeTenant());
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $invoiceId = $this->postJson("/api/v1/invoices/generate-from-billing/{$billingId}")->assertCreated()->json('data.id');
        $this->assertCount(0, $this->mailsFor($admin), 'A draft is not announced.');

        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/invoices/{$invoiceId}/issue")->assertOk();

        $invoice = Invoice::findOrFail($invoiceId);
        $mail = $this->mailsFor($admin)->sole();
        $this->assertSame("Invoice baru {$invoice->invoice_number} perlu ditindaklanjuti", $mail->subject);
        $this->assertStringContainsString('Total: IDR 1.500.000,00', $mail->body);
        $this->assertStringContainsString('selesaikan pembayaran sebelum '.$invoice->due_date->format('d/m/Y'), $mail->body);
        $this->assertStringContainsString('https://nexus.example.test/invoices/'.$invoiceId, $mail->body_html);
        $this->assertCount(0, $this->mailsFor($stranger));
    }

    public function test_cancelling_a_draft_that_was_never_announced_sends_nothing(): void
    {
        [$tenant, $billingId] = $this->finalizedBillingFor();
        $admin = $this->tenantAdmin($tenant);
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $invoiceId = $this->postJson("/api/v1/invoices/generate-from-billing/{$billingId}")->assertCreated()->json('data.id');
        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/invoices/{$invoiceId}/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        $this->assertCount(0, $this->mailsFor($admin));
    }

    public function test_unpaid_invoice_past_due_becomes_overdue_once_and_stays_overdue_after_a_partial_payment(): void
    {
        [$tenant, $invoiceId] = $this->issuedInvoice();
        $admin = $this->tenantAdmin($tenant);
        Invoice::whereKey($invoiceId)->update(['due_date' => now()->subDay()->toDateString()]);

        $this->artisan('invoices:process-due-dates')->assertSuccessful();
        $this->artisan('invoices:process-due-dates')->assertSuccessful();

        $invoice = Invoice::findOrFail($invoiceId);
        $this->assertSame('OVERDUE', $invoice->status);
        $mail = $this->mailsFor($admin)->sole();
        $this->assertSame("Status invoice {$invoice->invoice_number}: Lewat jatuh tempo (OVERDUE)", $mail->subject);
        $this->assertStringContainsString('telah melewati jatuh tempo', $mail->body);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.overdue', 'resource_id' => $invoiceId]);

        $this->actingAsCommercialRole('BILLING_ADMIN');
        $this->postJson("/api/v1/invoices/{$invoiceId}/mark-partially-paid", ['amount' => '100000.00'])
            ->assertOk()->assertJsonPath('data.status', 'OVERDUE');
        $this->postJson("/api/v1/invoices/{$invoiceId}/mark-paid")->assertOk()->assertJsonPath('data.status', 'PAID');
        $this->artisan('invoices:process-due-dates')->assertSuccessful();
        $this->assertSame('PAID', Invoice::findOrFail($invoiceId)->status);
    }

    public function test_invoice_due_soon_gets_exactly_one_reminder_and_paid_or_distant_invoices_get_none(): void
    {
        config(['notifications.invoice_due_reminder_days' => 3]);
        [$tenant, $invoiceId] = $this->issuedInvoice();
        $admin = $this->tenantAdmin($tenant);
        Invoice::whereKey($invoiceId)->update(['due_date' => now()->addDays(10)->toDateString()]);

        $this->artisan('invoices:process-due-dates')->assertSuccessful();
        $this->assertCount(0, $this->mailsFor($admin));

        Invoice::whereKey($invoiceId)->update(['due_date' => now()->addDays(2)->toDateString()]);
        $this->artisan('invoices:process-due-dates')->assertSuccessful();
        $this->artisan('invoices:process-due-dates')->assertSuccessful();

        $invoice = Invoice::findOrFail($invoiceId);
        $mail = $this->mailsFor($admin)->sole();
        $this->assertSame("Pengingat: invoice {$invoice->invoice_number} jatuh tempo ".$invoice->due_date->format('d/m/Y'), $mail->subject);
        $this->assertNotNull($invoice->due_reminder_sent_at);
        $this->assertSame('ISSUED', $invoice->status);

        [$otherTenant, $paidId] = $this->issuedInvoice();
        $otherAdmin = $this->tenantAdmin($otherTenant);
        Invoice::whereKey($paidId)->update(['due_date' => now()->addDay()->toDateString(), 'status' => 'PAID', 'balance_due' => '0.00']);
        $this->artisan('invoices:process-due-dates')->assertSuccessful();
        $this->assertSame(0, Notification::where('recipient_user_id', $otherAdmin->id)->where('subject', 'like', 'Pengingat:%')->count());
    }

    public function test_reminders_can_be_switched_off(): void
    {
        config(['notifications.invoice_due_reminder_days' => 0]);
        [$tenant, $invoiceId] = $this->issuedInvoice();
        $admin = $this->tenantAdmin($tenant);
        Invoice::whereKey($invoiceId)->update(['due_date' => now()->addDay()->toDateString()]);

        $this->artisan('invoices:process-due-dates')->assertSuccessful();

        $this->assertCount(0, $this->mailsFor($admin));
        $this->assertNull(Invoice::findOrFail($invoiceId)->due_reminder_sent_at);
    }

    public function test_english_locale_and_amount_formatting_never_use_floats(): void
    {
        $composer = app(OperationalMailComposer::class);
        $this->assertSame('IDR 12.345.678,90', $composer->money('IDR', '12345678.90'));
        $this->assertSame('IDR 999', $composer->money('IDR', '999'));
        $this->assertSame('IDR -1.000,00', $composer->money('IDR', '-1000.00'));
        // 2^53 + 1 survives because no float is involved.
        $this->assertSame('USD 9.007.199.254.740.993,01', $composer->money('USD', '9007199254740993.01'));

        config(['notifications.mail_locale' => 'en']);
        $this->assertSame('USD 1,000,000.00', $composer->money('USD', '1000000.00'));
        $this->seedGovernanceBaseline();
        $tenant = $this->makeTenant(null, ['name' => 'Acme']);
        $admin = $this->tenantAdmin($tenant);
        $tenant->update(['status' => 'SUSPENDED']);
        $this->assertSame('Tenant Acme is now Suspended (SUSPENDED)', $this->mailsFor($admin)->sole()->subject);
    }

    /** @return array{0: Tenant, 1: string} */
    private function finalizedBillingFor(): array
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $customer = $this->makeCustomer();
        $tenant = $this->makeTenant($customer);
        $plan = $this->makePlan($this->makeProduct());
        $this->makeFlatPrice($plan, ['unit_amount' => '1500000.00', 'currency' => 'IDR']);
        $subscriptionId = $this->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        ])->json('data.id');
        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/activate")->assertOk();
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $billingId = $this->postJson('/api/v1/billing-runs', ['subscription_id' => $subscriptionId])->json('data.results.0.id');
        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/billings/{$billingId}/finalize")->assertOk();

        return [$tenant, $billingId];
    }

    /** An ISSUED invoice whose tenant has no admin yet (the caller registers one, so issuance noise stays out of the count). */
    private function issuedInvoice(): array
    {
        [$tenant, $billingId] = $this->finalizedBillingFor();
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $invoiceId = $this->postJson("/api/v1/invoices/generate-from-billing/{$billingId}")->assertCreated()->json('data.id');
        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/invoices/{$invoiceId}/issue")->assertOk();

        return [$tenant, $invoiceId];
    }
}
