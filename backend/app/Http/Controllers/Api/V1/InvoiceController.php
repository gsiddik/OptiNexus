<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Billing;
use App\Models\Invoice;
use App\Http\Resources\V1\InvoiceResource;
use App\Services\AuditService;
use App\Services\Commercial\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    use ApiResponses;

    private const ERROR_STATUS = [
        'BILLING_NOT_FINALIZED' => 422,
        'DUPLICATE_RESOURCE' => 409,
        'INVALID_INVOICE_TRANSITION' => 409,
    ];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Invoice::query();

        foreach (['tenant_id', 'customer_id', 'subscription_id', 'status'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, InvoiceResource::class);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return $this->ok(new InvoiceResource($invoice->load(['items', 'payments'])));
    }

    public function generateFromBilling(Request $request, Billing $billing): JsonResponse
    {
        $netDays = (int) $request->input('net_days', 30);
        [$invoice, $error] = $this->invoices->generateFromBilling($billing, $netDays);

        if ($error) {
            return $this->errorResponse($error, $error === 'DUPLICATE_RESOURCE' ? 'An invoice already exists for this billing.' : 'Only a finalized billing can be turned into an invoice.');
        }

        $this->audit->record('invoice.generated', $request, resourceType: 'Invoice', resourceId: $invoice->id, newValue: ['total' => $invoice->total], tenantId: $invoice->tenant_id, customerId: $invoice->customer_id);

        return $this->created(new InvoiceResource($invoice));
    }

    public function issue(Request $request, Invoice $invoice): JsonResponse
    {
        $error = $this->invoices->issue($invoice);
        if ($error) {
            return $this->errorResponse($error, 'Only a draft invoice can be issued.');
        }

        $invoice->refresh();
        $this->audit->record('invoice.issued', $request, resourceType: 'Invoice', resourceId: $invoice->id, newValue: ['status' => $invoice->status], tenantId: $invoice->tenant_id, customerId: $invoice->customer_id);

        return $this->ok(new InvoiceResource($invoice));
    }

    public function markPaid(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:150'],
        ]);

        [, $error] = $this->invoices->recordPayment($invoice, $invoice->balance_due, $validated['method'] ?? null, $validated['reference'] ?? null, $request->user()?->id);
        if ($error) {
            return $this->errorResponse($error, 'Only an issued or partially paid invoice can be marked paid.');
        }

        $invoice->refresh();
        $this->audit->record('invoice.status_changed', $request, resourceType: 'Invoice', resourceId: $invoice->id, newValue: ['status' => $invoice->status], tenantId: $invoice->tenant_id);

        return $this->ok(new InvoiceResource($invoice->load('payments')));
    }

    public function markPartiallyPaid(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:150'],
        ]);

        [, $error] = $this->invoices->recordPayment($invoice, (string) $validated['amount'], $validated['method'] ?? null, $validated['reference'] ?? null, $request->user()?->id);
        if ($error) {
            return $this->errorResponse($error, is_string($error) && str_contains($error, 'exceeds') ? $error : 'Only an issued or partially paid invoice can receive a payment.');
        }

        $invoice->refresh();
        $this->audit->record('invoice.status_changed', $request, resourceType: 'Invoice', resourceId: $invoice->id, newValue: ['status' => $invoice->status, 'balance_due' => $invoice->balance_due], tenantId: $invoice->tenant_id);

        return $this->ok(new InvoiceResource($invoice->load('payments')));
    }

    public function void(Request $request, Invoice $invoice): JsonResponse
    {
        $error = $this->invoices->void($invoice);
        if ($error) {
            return $this->errorResponse($error, 'Only an issued invoice can be voided.');
        }

        $invoice->refresh();
        $this->audit->record('invoice.voided', $request, resourceType: 'Invoice', resourceId: $invoice->id, tenantId: $invoice->tenant_id);

        return $this->ok(new InvoiceResource($invoice));
    }

    public function cancel(Request $request, Invoice $invoice): JsonResponse
    {
        $error = $this->invoices->cancel($invoice);
        if ($error) {
            return $this->errorResponse($error, 'Only a draft invoice can be cancelled.');
        }

        $invoice->refresh();
        $this->audit->record('invoice.cancelled', $request, resourceType: 'Invoice', resourceId: $invoice->id, tenantId: $invoice->tenant_id);

        return $this->ok(new InvoiceResource($invoice));
    }

    private function errorResponse(string $code, string $message): JsonResponse
    {
        $status = self::ERROR_STATUS[$code] ?? 422;

        return $this->fail(array_key_exists($code, self::ERROR_STATUS) ? $code : 'VALIDATION_ERROR', $message, $status);
    }
}
