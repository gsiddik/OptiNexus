<?php

namespace Database\Seeders;

/**
 * Registers the events OptiFleet reports to OptiNexus (POST /api/v1/events)
 * in the Event Catalog; the envelope is described in the base class. Here
 * aggregate_id is the id of the record in OptiFleet and correlation names the
 * work order or partner. Money is always a decimal string, never a float.
 *
 * Run: php artisan db:seed --class=OptiFleetEventCatalogSeeder
 * The application with code `optifleet` must exist first.
 */
class OptiFleetEventCatalogSeeder extends ApplicationEventCatalogSeeder
{
    public const APPLICATION_CODE = 'optifleet';

    /** @var array<string, array{name: string, description: string}> */
    public const EVENTS = [
        'optifleet.workshop_invoice.recorded' => [
            'name' => 'Workshop invoice recorded',
            'description' => 'A workshop invoice was recorded against a maintenance memo (memo becomes BILLED). payload: external_invoice_number, total_amount, currency.',
        ],
        'optifleet.workshop_invoice.corrected' => [
            'name' => 'Workshop invoice corrected',
            'description' => 'A correction of a recorded invoice was approved. aggregate_id is the correction. payload: requested_values, previous_values.',
        ],
        'optifleet.workshop_invoice.cancelled' => [
            'name' => 'Workshop invoice cancelled',
            'description' => 'A cancellation of a recorded invoice was approved. aggregate_id is the cancellation. payload: reason, had_payment.',
        ],
        'optifleet.workshop_invoice.payment_recorded' => [
            'name' => 'Workshop invoice payment recorded',
            'description' => 'Payment evidence was recorded for an invoice. aggregate_id is the payment. payload: paid_amount, payment_date.',
        ],
        'optifleet.maintenance_memo.billed' => [
            'name' => 'Maintenance memo billed',
            'description' => 'A maintenance memo moved to BILLED. payload: memo_number, workshop_invoice_id.',
        ],
        'optifleet.maintenance_memo.paid' => [
            'name' => 'Maintenance memo paid',
            'description' => 'A maintenance memo moved to PAID. payload: memo_number.',
        ],
    ];

    protected function applicationCode(): string
    {
        return self::APPLICATION_CODE;
    }

    protected function events(): array
    {
        return self::EVENTS;
    }
}
