<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Server-side, concurrency-safe document numbering backed by PostgreSQL
 * sequences (see the create_commercial_numbering_sequences migration).
 * A sequence's nextval() is atomic across concurrent transactions, so two
 * simultaneous requests can never receive the same number.
 */
class DocumentNumberService
{
    public function nextSubscriptionNumber(): string
    {
        return $this->format('SUB', 'subscription_number_seq');
    }

    public function nextBillingNumber(): string
    {
        return $this->format('BIL', 'billing_number_seq');
    }

    public function nextInvoiceNumber(): string
    {
        return $this->format('INV', 'invoice_number_seq');
    }

    private function format(string $prefix, string $sequence): string
    {
        $next = DB::selectOne("select nextval('{$sequence}') as n")->n;
        $year = now()->format('Y');

        return sprintf('%s-%s-%06d', $prefix, $year, $next);
    }
}
