<?php

namespace App\Services\Commercial;

use App\Models\Invoice;
use App\Services\AuditService;
use App\Services\Notification\Operational\OperationalMailComposer;
use App\Services\Notification\OperationalMailService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Due-date follow-up for issued invoices: an unpaid invoice past its due date becomes OVERDUE (the status-change notice is sent by
 * OperationalMailObserver), and an unpaid invoice close to its due date gets one reminder. Each invoice is re-read under a row
 * lock, so a payment or a cancellation that commits first wins and two scheduler runs never act twice.
 */
class InvoiceDueService
{
    private const UNPAID = [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID];

    public function __construct(
        private readonly AuditService $audit,
        private readonly OperationalMailService $mail,
        private readonly OperationalMailComposer $composer,
    ) {}

    public function markOverdue(): int
    {
        $today = now()->toDateString();
        $marked = 0;
        foreach (Invoice::query()->whereIn('status', self::UNPAID)->where('due_date', '<', $today)->where('balance_due', '>', 0)->pluck('id') as $id) {
            $marked += DB::transaction(function () use ($id, $today) {
                $invoice = Invoice::query()->lockForUpdate()->find($id);
                if (! $invoice || ! in_array($invoice->status, self::UNPAID, true) || ! $invoice->due_date
                    || $invoice->due_date->toDateString() >= $today || Money::compare((string) $invoice->balance_due, Money::zero()) <= 0) {
                    return 0;
                }
                $previous = $invoice->status;
                $invoice->update(['status' => Invoice::STATUS_OVERDUE]);
                $this->audit->record('invoice.overdue', null, actorIdentity: 'system', tenantId: $invoice->tenant_id,
                    customerId: $invoice->customer_id, resourceType: 'Invoice', resourceId: $invoice->id,
                    oldValue: ['status' => $previous], newValue: ['status' => Invoice::STATUS_OVERDUE, 'due_date' => $invoice->due_date->toDateString()]);

                return 1;
            });
        }

        return $marked;
    }

    public function sendDueReminders(): int
    {
        $days = (int) config('notifications.invoice_due_reminder_days', 3);
        if ($days <= 0) {
            return 0;
        }
        $today = now()->toDateString();
        $until = now()->addDays($days)->toDateString();
        $sent = 0;
        $candidates = Invoice::query()->whereIn('status', self::UNPAID)->whereNull('due_reminder_sent_at')
            ->whereBetween('due_date', [$today, $until])->where('balance_due', '>', 0)->pluck('id');
        foreach ($candidates as $id) {
            $sent += DB::transaction(function () use ($id, $today, $until) {
                $invoice = Invoice::query()->lockForUpdate()->find($id);
                $due = $invoice?->due_date?->toDateString();
                if (! $invoice || ! in_array($invoice->status, self::UNPAID, true) || $invoice->due_reminder_sent_at
                    || $due === null || $due < $today || $due > $until || Money::compare((string) $invoice->balance_due, Money::zero()) <= 0) {
                    return 0;
                }
                $invoice->update(['due_reminder_sent_at' => now()]);
                $this->mail->send([$invoice->tenant_id], 'cgo.invoice.view', $this->composer->invoiceDueReminder($invoice));

                return 1;
            });
        }

        return $sent;
    }
}
