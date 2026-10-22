<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InvoicePaymentResource;
use App\Http\Resources\V1\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use App\Services\Commercial\SubmittedPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Authenticated application's portal: tenant, product, actor and permission are bound on every operation. */
class CommercialPortalController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly SubmittedPaymentService $payments) {}

    private function authorizePortal(Request $request, Tenant $tenant, string $permission): User
    {
        $account = $request->attributes->get('service_account');
        $app = $account->application;
        abort_unless($app && $app->application_code === 'optifleet', 403);
        abort_if($account->tenant_id !== null && $account->tenant_id !== $tenant->id, 403);
        $data = $request->validate(['acting_user_id' => ['required', 'uuid']]);
        $user = User::findOrFail($data['acting_user_id']);
        abort_unless(TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'ACTIVE')->exists(), 403);
        $answer = app(AuthorizationService::class)->check($user, $tenant, $app, 'optifleet.'.$permission);
        abort_unless($answer['allowed'], 403, 'Central commercial permission denied.');

        return $user;
    }

    private function invoices(Tenant $tenant)
    {
        $application = request()->attributes->get('service_account')->application;

        return Invoice::query()->where('tenant_id', $tenant->id)
            ->whereHas('billing', fn ($q) => $q->where('status', 'FINALIZED'))
            ->whereHas('subscription.product.applications', fn ($q) => $q->where('applications.id', $application->id));
    }

    private function payment(Tenant $tenant, string $id): InvoicePayment
    {
        return InvoicePayment::query()->whereIn('invoice_id', $this->invoices($tenant)->select('id'))->findOrFail($id);
    }

    public function invoiceIndex(Request $request, Tenant $tenant)
    {
        $this->authorizePortal($request, $tenant, 'account.invoice.view');
        $q = $this->invoices($tenant);
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($request->boolean('payable')) {
            $q->whereIn('status', ['ISSUED', 'PARTIALLY_PAID', 'OVERDUE'])->where('balance_due', '>', 0)
                ->whereDoesntHave('payments', fn ($q) => $q->where('status', 'SUBMITTED'));
        }

        return $this->paginated($q->latest()->paginate(min(100, max(1, $request->integer('per_page', 20)))), InvoiceResource::class);
    }

    public function invoiceShow(Request $request, Tenant $tenant, string $invoice)
    {
        $this->authorizePortal($request, $tenant, 'account.invoice.view');

        return $this->ok(new InvoiceResource($this->invoices($tenant)->with(['items', 'payments'])->findOrFail($invoice)));
    }

    public function paymentIndex(Request $request, Tenant $tenant)
    {
        $this->authorizePortal($request, $tenant, 'account.payment.view');
        $q = InvoicePayment::query()->whereIn('invoice_id', $this->invoices($tenant)->select('id'));
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return $this->paginated($q->with('invoice')->latest()->paginate(min(100, max(1, $request->integer('per_page', 20)))), InvoicePaymentResource::class);
    }

    public function paymentShow(Request $request, Tenant $tenant, string $payment)
    {
        $this->authorizePortal($request, $tenant, 'account.payment.view');

        return $this->ok(new InvoicePaymentResource($this->payment($tenant, $payment)));
    }

    public function submit(Request $request, Tenant $tenant)
    {
        $actor = $this->authorizePortal($request, $tenant, 'account.payment.submit');
        $data = $request->validate([
            'invoice_id' => ['required', 'uuid'], 'submission_key' => ['required', 'uuid'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999999.99'],
            'payment_method' => ['required', 'string', 'max:50'], 'transaction_reference' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'], 'account_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'], ...$this->proofRules(false),
        ]);
        $invoice = $this->invoices($tenant)->findOrFail($data['invoice_id']);
        $payment = $this->payments->submit($invoice, $data, $actor->id);
        app(AuditService::class)->record('invoice.payment_submitted', $request, actor: $actor, tenantId: $tenant->id, resourceType: 'InvoicePayment', resourceId: $payment->id);

        return $this->created(new InvoicePaymentResource($payment));
    }

    public function attachProof(Request $request, Tenant $tenant, string $payment)
    {
        $this->authorizePortal($request, $tenant, 'account.payment.submit');
        $data = $request->validate($this->proofRules(true));

        return $this->ok(new InvoicePaymentResource($this->payments->attachProof($this->payment($tenant, $payment), $data['proof'])));
    }

    public function proof(Request $request, Tenant $tenant, string $payment)
    {
        $this->authorizePortal($request, $tenant, 'account.payment.view');
        $proof = $this->payment($tenant, $payment)->proof;
        abort_unless($proof, 404);

        return $this->ok(['content' => base64_encode(Storage::disk('local')->get($proof['path'])),
            'filename' => $proof['original_filename'], 'mime_type' => $proof['mime_type'], 'id' => $proof['id']]);
    }

    private function proofRules(bool $required): array
    {
        return ['proof' => [$required ? 'required' : 'nullable', 'array'],
            'proof.content' => ['required_with:proof', 'string', 'max:6990508'],
            'proof.filename' => ['required_with:proof', 'string', 'max:255']];
    }
}
