<?php

namespace App\Services\Commercial;

use App\Models\Billing;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Support\DocumentNumberService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Generates an immutable financial snapshot (Invoice + InvoiceItems) from a
 * finalized Billing. Once issued, an invoice's totals never change even if
 * the underlying Billing, Plan, or Price definitions change later.
 */
class InvoiceService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @return array{0: ?Invoice, 1: ?string}
     */
    public function generateFromBilling(Billing $billing, int $netDays = 30): array
    {
        [$invoice, $error] = DB::transaction(function () use ($billing, $netDays) {
            $billing = Billing::query()->lockForUpdate()->findOrFail($billing->id);
            if (! $billing->isFinalized()) {
                return [null, 'BILLING_NOT_FINALIZED'];
            }
            if (Invoice::query()->where('billing_id', $billing->id)->where('status', '!=', Invoice::STATUS_CANCELLED)->exists()) {
                return [null, 'DUPLICATE_RESOURCE'];
            }
            $invoice = Invoice::create([
                'invoice_number' => $this->numbers->nextInvoiceNumber(),
                'billing_id' => $billing->id,
                'customer_id' => $billing->customer_id,
                'tenant_id' => $billing->tenant_id,
                'subscription_id' => $billing->subscription_id,
                'currency' => $billing->currency,
                'subtotal' => $billing->subtotal,
                'discount_total' => $billing->discount_total,
                'tax_total' => $billing->tax_total,
                'adjustment_total' => $billing->adjustment_total,
                'total' => $billing->total,
                'balance_due' => $billing->total,
                'status' => Invoice::STATUS_DRAFT,
                'due_date' => now()->addDays($netDays)->toDateString(),
            ]);

            foreach ($billing->items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_amount' => $item->unit_amount,
                    'subtotal' => $item->subtotal,
                    'discount_amount' => $item->discount_amount,
                    'tax_amount' => $item->tax_amount,
                    'total' => $item->total,
                    'source_billing_item_id' => $item->id,
                ]);
            }

            foreach ($billing->adjustments as $adjustment) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => "Adjustment: {$adjustment->reason}",
                    'quantity' => 1,
                    'unit_amount' => $adjustment->amount,
                    'subtotal' => $adjustment->amount,
                    'discount_amount' => Money::zero(),
                    'tax_amount' => Money::zero(),
                    'total' => $adjustment->amount,
                ]);
            }

            return [$invoice, null];
        });

        return [$invoice?->fresh('items'), $error];
    }

    public function issue(Invoice $invoice): ?string
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== Invoice::STATUS_DRAFT) {
                return 'INVALID_INVOICE_TRANSITION';
            }
            $invoice->update([
                'status' => Invoice::STATUS_ISSUED,
                'issue_date' => now()->toDateString(),
                'issued_at' => now(),
            ]);

            return null;
        });
    }

    public function recordPayment(Invoice $invoice, string $amount, ?string $method, ?string $reference, ?string $recordedBy): array
    {
        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $recordedBy) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->payments()->where('status', 'SUBMITTED')->exists()) {
                return [null, 'Review the submitted payment from its detail page.'];
            }
            if (Money::compare($amount, '0.00') <= 0) {
                return [null, 'Payment must be positive.'];
            }

            return $this->recordConfirmedPayment($invoice, $amount, $method, $reference, $recordedBy);
        });
    }

    private function recordConfirmedPayment(Invoice $invoice, string $amount, ?string $method, ?string $reference, ?string $recordedBy): array
    {
        if (! in_array($invoice->status, [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID, Invoice::STATUS_OVERDUE], true)) {
            return [null, 'INVALID_INVOICE_TRANSITION'];
        }

        if (Money::compare($amount, $invoice->balance_due) > 0) {
            return [null, 'Payment amount exceeds the outstanding balance due.'];
        }

        $payment = DB::transaction(function () use ($invoice, $amount, $method, $reference, $recordedBy) {
            $payment = InvoicePayment::create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'paid_at' => now(),
                'method' => $method,
                'reference' => $reference,
                'recorded_by' => $recordedBy,
            ]);

            $newBalance = Money::subtract($invoice->balance_due, $amount);
            $fullyPaid = Money::compare($newBalance, Money::zero()) <= 0;

            $invoice->update([
                'balance_due' => $fullyPaid ? Money::zero() : $newBalance,
                'status' => $fullyPaid ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIALLY_PAID,
                'paid_at' => $fullyPaid ? now() : $invoice->paid_at,
            ]);

            return $payment;
        });

        return [$payment, null];
    }

    public function void(Invoice $invoice): ?string
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($invoice->status, [Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE], true)) {
                return 'INVALID_INVOICE_TRANSITION';
            }
            $invoice->update(['status' => Invoice::STATUS_VOID, 'cancelled_at' => now()]);

            return null;
        });
    }

    public function cancel(Invoice $invoice): ?string
    {
        return DB::transaction(function () use ($invoice) {
            // Match generation's lock order: billing, then invoice.
            Billing::query()->whereKey($invoice->billing_id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($invoice->status, [Invoice::STATUS_DRAFT, Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE], true)
                || $invoice->payments()->whereIn('status', ['SUBMITTED', 'VERIFIED'])->exists()) {
                return 'INVALID_INVOICE_TRANSITION';
            }
            // Preserve the original issue_date and issued_at on the historical invoice.
            $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancelled_at' => now()]);

            return null;
        });
    }
}
