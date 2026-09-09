<?php

namespace Tests\Feature\Commercial;

use App\Models\Billing;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    private function finalizedBilling(): array
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $customer = $this->makeCustomer();
        $tenant = $this->makeTenant($customer);
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $this->makeFlatPrice($plan, ['unit_amount' => '1500000.00']);

        $subscriptionId = $this->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        ])->json('data.id');
        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/activate")->assertStatus(200);

        $this->actingAsCommercialRole('BILLING_ADMIN');
        $billingId = $this->postJson('/api/v1/billing-runs', ['subscription_id' => $subscriptionId])->json('data.results.0.id');

        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/billings/{$billingId}/finalize")->assertStatus(200);

        return [$tenant, $billingId];
    }

    public function test_invoice_cannot_be_generated_from_a_non_finalized_billing(): void
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $customer = $this->makeCustomer();
        $tenant = $this->makeTenant($customer);
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $this->makeFlatPrice($plan);
        $subscriptionId = $this->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        ])->json('data.id');
        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/activate")->assertStatus(200);

        $this->actingAsCommercialRole('BILLING_ADMIN');
        $billingId = $this->postJson('/api/v1/billing-runs', ['subscription_id' => $subscriptionId])->json('data.results.0.id');

        $this->actingAsCommercialRole('BILLING_ADMIN');
        $this->postJson("/api/v1/invoices/generate-from-billing/{$billingId}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BILLING_NOT_FINALIZED');
    }

    public function test_invoice_numbers_are_unique_and_server_generated(): void
    {
        [, $billingId] = $this->finalizedBilling();
        $this->actingAsCommercialRole('BILLING_ADMIN');

        $response = $this->postJson("/api/v1/invoices/generate-from-billing/{$billingId}", ['invoice_number' => 'CLIENT-FORGED-0001'])
            ->assertStatus(201);

        $this->assertNotSame('CLIENT-FORGED-0001', $response->json('data.invoice_number'));
        $this->assertNotEmpty($response->json('data.invoice_number'));
    }

    public function test_generating_an_invoice_twice_for_the_same_billing_is_rejected(): void
    {
        [, $billingId] = $this->finalizedBilling();
        $this->actingAsCommercialRole('BILLING_ADMIN');

        $this->postJson("/api/v1/invoices/generate-from-billing/{$billingId}")->assertStatus(201);
        $this->postJson("/api/v1/invoices/generate-from-billing/{$billingId}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_RESOURCE');

        $this->assertSame(1, Invoice::query()->where('billing_id', $billingId)->count());
    }

    public function test_invoice_is_immutable_after_issuance_even_if_the_billing_changes(): void
    {
        [, $billingId] = $this->finalizedBilling();
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $invoiceId = $this->postJson("/api/v1/invoices/generate-from-billing/{$billingId}")->json('data.id');
        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/invoices/{$invoiceId}/issue")->assertStatus(200);

        $totalAtIssuance = Invoice::findOrFail($invoiceId)->total;

        // Directly mutate the billing (simulating drift) - the issued
        // invoice must not reflect it since it is a frozen snapshot.
        Billing::query()->where('id', $billingId)->update(['total' => '1.00']);

        $invoice = Invoice::findOrFail($invoiceId);
        $this->assertSame($totalAtIssuance, $invoice->total);
    }

    public function test_only_a_draft_invoice_can_be_issued_or_cancelled(): void
    {
        [, $billingId] = $this->finalizedBilling();
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $invoiceId = $this->postJson("/api/v1/invoices/generate-from-billing/{$billingId}")->json('data.id');
        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/invoices/{$invoiceId}/issue")->assertStatus(200);

        $this->postJson("/api/v1/invoices/{$invoiceId}/issue")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_INVOICE_TRANSITION');

        $this->postJson("/api/v1/invoices/{$invoiceId}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_INVOICE_TRANSITION');
    }

    public function test_billing_admin_cannot_void_an_invoice_but_billing_approver_can(): void
    {
        [, $billingId] = $this->finalizedBilling();
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $invoiceId = $this->postJson("/api/v1/invoices/generate-from-billing/{$billingId}")->json('data.id');
        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/invoices/{$invoiceId}/issue")->assertStatus(200);

        // Segregation of duties: BILLING_ADMIN lacks cgo.invoice.void.
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $this->postJson("/api/v1/invoices/{$invoiceId}/void")->assertStatus(403);

        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/invoices/{$invoiceId}/void")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'VOID');
    }

    public function test_marking_paid_updates_balance_and_status(): void
    {
        [, $billingId] = $this->finalizedBilling();
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $invoiceId = $this->postJson("/api/v1/invoices/generate-from-billing/{$billingId}")->json('data.id');
        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/invoices/{$invoiceId}/issue")->assertStatus(200);

        // Payment recording is BILLING_ADMIN's job, not BILLING_APPROVER's.
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $this->postJson("/api/v1/invoices/{$invoiceId}/mark-paid")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.balance_due', '0.00');
    }

    public function test_partial_payment_cannot_exceed_the_balance_due(): void
    {
        [, $billingId] = $this->finalizedBilling();
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $invoiceId = $this->postJson("/api/v1/invoices/generate-from-billing/{$billingId}")->json('data.id');
        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/invoices/{$invoiceId}/issue")->assertStatus(200);
        $total = Invoice::findOrFail($invoiceId)->total;

        $this->actingAsCommercialRole('BILLING_ADMIN');

        $this->postJson("/api/v1/invoices/{$invoiceId}/mark-partially-paid", ['amount' => (float) $total + 1000000])
            ->assertStatus(422);
    }
}
