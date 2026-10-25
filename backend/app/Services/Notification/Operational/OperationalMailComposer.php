<?php

namespace App\Services\Notification\Operational;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Builds the operational notices (docs/OPERATIONAL_EMAIL.md) in the configured mail language. Amounts are formatted from their
 * decimal strings, never through floats.
 */
class OperationalMailComposer
{
    public function customerStatus(Customer $customer, ?string $previous): OperationalMailMessage
    {
        $status = $this->status($customer->status);

        return new OperationalMailMessage(
            $this->t('customer.subject', ['name' => $customer->legal_name, 'status' => $status]),
            [
                $this->t('customer.intro', ['name' => $customer->legal_name, 'code' => $customer->customer_code, 'status' => $status]),
                $this->t('customer.follow_up'),
            ],
            [
                $this->fact('customer') => $customer->legal_name,
                $this->fact('customer_code') => (string) $customer->customer_code,
                $this->fact('previous_status') => $this->status($previous),
                $this->fact('new_status') => $status,
            ],
            $this->url("/customers/{$customer->id}"),
        );
    }

    public function tenantStatus(Tenant $tenant, ?string $previous): OperationalMailMessage
    {
        $status = $this->status($tenant->status);

        return new OperationalMailMessage(
            $this->t('tenant.subject', ['name' => $tenant->name, 'status' => $status]),
            [$this->t('tenant.intro', ['name' => $tenant->name, 'status' => $status]), $this->t('tenant.follow_up')],
            [
                $this->fact('tenant') => $tenant->name,
                $this->fact('previous_status') => $this->status($previous),
                $this->fact('new_status') => $status,
            ],
            $this->url("/tenants/{$tenant->id}"),
        );
    }

    public function userStatus(User $user, ?string $previous): OperationalMailMessage
    {
        $status = $this->status($user->status);

        return new OperationalMailMessage(
            $this->t('user.subject', ['name' => $user->name, 'status' => $status]),
            [$this->t('user.intro', ['name' => $user->name, 'email' => $user->email, 'status' => $status]), $this->t('user.follow_up')],
            [
                $this->fact('user') => (string) $user->name,
                $this->fact('email') => (string) $user->email,
                $this->fact('previous_status') => $this->status($previous),
                $this->fact('new_status') => $status,
            ],
            $this->url("/users/{$user->id}"),
        );
    }

    public function invoiceStatus(Invoice $invoice, ?string $previous): OperationalMailMessage
    {
        $tenant = (string) $invoice->tenant?->name;
        $due = $this->date($invoice->due_date);
        if ($invoice->status === Invoice::STATUS_ISSUED && in_array($previous, [null, Invoice::STATUS_DRAFT], true)) {
            $subject = $this->t('invoice.issued_subject', ['number' => $invoice->invoice_number]);
            $paragraphs = [
                $this->t('invoice.issued_intro', ['number' => $invoice->invoice_number, 'tenant' => $tenant]),
                $this->t('invoice.issued_follow_up', ['due' => $due]),
            ];
        } else {
            $subject = $this->t('invoice.status_subject', ['number' => $invoice->invoice_number, 'status' => $this->status($invoice->status)]);
            $followUp = 'invoice.follow_up.'.$invoice->status;
            $paragraphs = [
                $this->t('invoice.status_intro', [
                    'number' => $invoice->invoice_number, 'tenant' => $tenant,
                    'old' => $this->status($previous), 'new' => $this->status($invoice->status),
                ]),
                $this->t(trans()->has('operational_mail.'.$followUp, $this->locale()) ? $followUp : 'invoice.follow_up.default'),
            ];
        }

        return new OperationalMailMessage($subject, $paragraphs, $this->invoiceFacts($invoice, $previous), $this->url("/invoices/{$invoice->id}"));
    }

    public function invoiceDueReminder(Invoice $invoice): OperationalMailMessage
    {
        $due = $this->date($invoice->due_date);
        $tenant = (string) $invoice->tenant?->name;

        return new OperationalMailMessage(
            $this->t('invoice.reminder_subject', ['number' => $invoice->invoice_number, 'due' => $due]),
            [
                $this->t('invoice.reminder_intro', ['number' => $invoice->invoice_number, 'tenant' => $tenant, 'due' => $due]),
                $this->t('invoice.reminder_follow_up'),
            ],
            $this->invoiceFacts($invoice, null),
            $this->url("/invoices/{$invoice->id}"),
        );
    }

    public function priceChanged(Price $price, Tenant $tenant): OperationalMailMessage
    {
        $item = (string) ($price->plan?->name ?? $price->addon?->name);
        $facts = [
            $this->fact('item') => $item,
            $this->fact('tenant') => $tenant->name,
            $this->fact('price_type') => (string) $price->price_type,
            $this->fact('currency') => (string) $price->currency,
            $this->fact('unit_amount') => $price->unit_amount !== null ? $this->money($price->currency, (string) $price->unit_amount) : $this->fact('tiered'),
            $this->fact('effective_from') => $this->dateTime($price->effective_from),
        ];
        if ($price->effective_until) {
            $facts[$this->fact('effective_until')] = $this->dateTime($price->effective_until);
        }

        return new OperationalMailMessage(
            $this->t('price.subject', ['item' => $item]),
            [$this->t('price.intro', ['item' => $item, 'tenant' => $tenant->name]), $this->t('price.follow_up')],
            $facts,
            $this->url("/prices/{$price->id}"),
        );
    }

    /** "1234567.50" -> "IDR 1.234.567,50" (id) or "IDR 1,234,567.50" (en). */
    public function money(?string $currency, ?string $amount): string
    {
        $amount = (string) ($amount ?? '0');
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-+'), 2), 2, '');
        [$thousands, $decimal] = $this->locale() === 'id' ? ['.', ','] : [',', '.'];
        $grouped = strrev(implode($thousands, str_split(strrev($whole === '' ? '0' : $whole), 3)));

        return trim(($currency ?? '').' '.($negative ? '-' : '').$grouped.($fraction !== '' ? $decimal.$fraction : ''));
    }

    public function locale(): string
    {
        $locale = (string) config('notifications.mail_locale', 'id');

        return in_array($locale, ['id', 'en'], true) ? $locale : 'en';
    }

    /** @return array<string, string> */
    private function invoiceFacts(Invoice $invoice, ?string $previous): array
    {
        $facts = [
            $this->fact('invoice_number') => (string) $invoice->invoice_number,
            $this->fact('tenant') => (string) $invoice->tenant?->name,
            $this->fact('total') => $this->money($invoice->currency, (string) $invoice->total),
            $this->fact('balance_due') => $this->money($invoice->currency, (string) $invoice->balance_due),
            $this->fact('due_date') => $this->date($invoice->due_date),
        ];
        if ($previous !== null) {
            $facts[$this->fact('previous_status')] = $this->status($previous);
        }
        $facts[$this->fact('new_status')] = $this->status($invoice->status);

        return $facts;
    }

    private function t(string $key, array $replace = []): string
    {
        return (string) __('operational_mail.'.$key, $replace, $this->locale());
    }

    private function fact(string $key): string
    {
        return $this->t('facts.'.$key);
    }

    private function status(?string $status): string
    {
        if ($status === null || $status === '') {
            return '-';
        }
        $key = 'operational_mail.statuses.'.$status;

        return trans()->has($key, $this->locale()) ? __($key, [], $this->locale()).' ('.$status.')' : $status;
    }

    private function date(?CarbonInterface $date): string
    {
        return $date ? $date->format('d/m/Y') : '-';
    }

    private function dateTime(?CarbonInterface $date): string
    {
        return $date ? $date->copy()->utc()->format('d/m/Y, H:i:s').' UTC' : '-';
    }

    private function url(string $path): string
    {
        return rtrim((string) config('notifications.console_url'), '/').$path;
    }
}
