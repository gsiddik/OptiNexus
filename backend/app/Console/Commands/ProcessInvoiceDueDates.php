<?php

namespace App\Console\Commands;

use App\Services\Commercial\InvoiceDueService;
use Illuminate\Console\Command;

class ProcessInvoiceDueDates extends Command
{
    protected $signature = 'invoices:process-due-dates';

    protected $description = 'Mark unpaid invoices past their due date as OVERDUE and send one reminder for unpaid invoices due within INVOICE_DUE_REMINDER_DAYS (each change e-mails the registered tenant admins)';

    public function handle(InvoiceDueService $due): int
    {
        $overdue = $due->markOverdue();
        $reminders = $due->sendDueReminders();
        $this->info("Marked {$overdue} invoice(s) overdue, sent {$reminders} due-date reminder(s).");

        return self::SUCCESS;
    }
}
