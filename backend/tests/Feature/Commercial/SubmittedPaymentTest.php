<?php

namespace Tests\Feature\Commercial;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\ServiceAccount;
use App\Services\AuthorizationService;
use App\Services\Commercial\SubmittedPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class SubmittedPaymentTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    private function invoice(): array
    {
        $actor = $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $customer = $this->makeCustomer();
        $tenant = $this->makeTenant($customer);
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $this->makeFlatPrice($plan);
        $app = $this->makeApplication(['application_code' => 'optifleet']);
        $product->applications()->attach($app->id, ['id' => (string) Str::uuid()]);
        $sub = $this->postJson('/api/v1/subscriptions', ['customer_id' => $customer->id, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id])->json('data.id');
        $this->postJson("/api/v1/subscriptions/$sub/activate")->assertOk();
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $billing = $this->postJson('/api/v1/billing-runs', ['subscription_id' => $sub])->json('data.results.0.id');
        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/billings/$billing/finalize")->assertOk();
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $id = $this->postJson("/api/v1/invoices/generate-from-billing/$billing")->assertCreated()->json('data.id');
        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/invoices/$id/issue")->assertOk();
        $this->actingAsCommercialRole('BILLING_ADMIN');

        return [Invoice::findOrFail($id), $actor, $tenant, $app];
    }

    private function data(Invoice $invoice): array
    {
        return ['submission_key' => (string) Str::uuid(), 'amount' => $invoice->balance_due, 'payment_date' => now()->toDateString(),
            'payment_method' => 'BANK_TRANSFER', 'bank_name' => 'BCA', 'account_name' => 'Customer', 'transaction_reference' => 'REF-1', 'note' => 'Invoice payment'];
    }

    public function test_submission_is_idempotent_rejection_allows_retry_and_verification_settles_once(): void
    {
        [$invoice, $actor] = $this->invoice();
        $service = app(SubmittedPaymentService::class);
        $data = $this->data($invoice);
        $payment = $service->submit($invoice, $data, $actor->id);
        $this->assertSame($payment->id, $service->submit($invoice, $data, $actor->id)->id);
        $this->assertSame('ISSUED', $invoice->fresh()->status);
        $this->assertSame($invoice->total, $invoice->fresh()->balance_due);
        $this->postJson("/api/v1/invoices/$invoice->id/mark-paid")->assertStatus(422);
        $this->postJson("/api/v1/invoice-payments/$payment->id/reject", ['note' => 'Unreadable proof'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $next = $service->submit($invoice, $this->data($invoice), $actor->id);
        $this->postJson("/api/v1/invoice-payments/$next->id/verify")->assertOk()->assertJsonPath('data.status', 'VERIFIED');
        $this->assertSame('PAID', $invoice->fresh()->status);
        $this->assertSame('0.00', $invoice->fresh()->balance_due);
        $this->postJson("/api/v1/invoice-payments/$next->id/verify")->assertStatus(409);
        $this->assertSame(2, InvoicePayment::count());
    }

    public function test_partial_and_duplicate_pending_submissions_are_refused(): void
    {
        [$invoice, $actor] = $this->invoice();
        $service = app(SubmittedPaymentService::class);
        try {
            $service->submit($invoice, [...$this->data($invoice), 'amount' => '1.00'], $actor->id);
            $this->fail();
        } catch (ValidationException) {
            $this->assertSame(0, InvoicePayment::count());
        }
        $service->submit($invoice, $this->data($invoice), $actor->id);
        $this->expectException(ValidationException::class);
        $service->submit($invoice, $this->data($invoice), $actor->id);
    }

    public function test_proof_is_private_viewable_downloadable_and_invalid_files_are_atomic(): void
    {
        Storage::fake('local');
        [$invoice, $actor] = $this->invoice();
        $service = app(SubmittedPaymentService::class);
        $payment = $service->submit($invoice, [...$this->data($invoice), 'proof' => ['filename' => 'proof.pdf', 'content' => base64_encode("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")]], $actor->id);
        $this->getJson("/api/v1/invoice-payments/$payment->id")->assertOk()->assertJsonMissingPath('data.proof.path');
        $this->get("/api/v1/invoice-payments/$payment->id/proof")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/api/v1/invoice-payments/$payment->id/proof?download=1")->assertOk()->assertDownload('proof.pdf');
        $this->postJson("/api/v1/invoice-payments/$payment->id/reject")->assertOk();
        try {
            $service->submit($invoice, [...$this->data($invoice), 'proof' => ['filename' => 'proof.pdf', 'content' => base64_encode('<script>bad</script>')]], $actor->id);
            $this->fail();
        } catch (ValidationException) {
            $this->assertSame(1, InvoicePayment::count());
        }
    }

    public function test_portal_binds_application_tenant_and_actor_and_rechecks_permission(): void
    {
        [$invoice, $actor, $tenant, $app] = $this->invoice();
        $token = $this->issueServiceAccountToken($app->id, ['commercial.read', 'commercial.payment.submit']);
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->mock(AuthorizationService::class, function ($mock) {
            $mock->shouldReceive('check')->andReturn(['allowed' => true]);
        });
        $base = "/api/v1/commercial-portal/tenants/$tenant->id";
        $this->getJson($base.'/invoices?acting_user_id='.$actor->id, $headers)->assertOk()->assertJsonPath('data.0.id', $invoice->id);
        $other = $this->makeTenant();
        $this->getJson("/api/v1/commercial-portal/tenants/$other->id/invoices/$invoice->id?acting_user_id=$actor->id", $headers)->assertNotFound();
        $payload = [...$this->data($invoice), 'invoice_id' => $invoice->id, 'acting_user_id' => $actor->id];
        $this->postJson($base.'/payments', $payload, $headers)->assertCreated()->assertJsonPath('data.status', 'SUBMITTED');
        $this->mock(AuthorizationService::class, function ($mock) {
            $mock->shouldReceive('check')->andReturn(['allowed' => false]);
        });
        $this->getJson($base.'/invoices?acting_user_id='.$actor->id, $headers)->assertForbidden();
        ServiceAccount::where('application_id', $app->id)->update(['tenant_id' => $other->id]);
        $this->getJson($base.'/invoices?acting_user_id='.$actor->id, $headers)->assertForbidden();
    }
}
