<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\EventCatalogEntry;
use Illuminate\Database\Seeder;

/**
 * Registers the events OptiFleet reports to OptiNexus (POST /api/v1/events)
 * in the Event Catalog. Idempotent: running it again updates the entries.
 *
 * Every event shares one envelope in `data`:
 *   aggregate_type  what the event is about (workshop_invoice, ...)
 *   aggregate_id    id of that record in OptiFleet
 *   correlation     ids that tie the event to its context (work order, partner)
 *   payload         the facts of this event type
 * Money is always a decimal string, never a float.
 *
 * Run: php artisan db:seed --class=OptiFleetEventCatalogSeeder
 * The application with code `optifleet` must exist first.
 */
class OptiFleetEventCatalogSeeder extends Seeder
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

    public const PAYLOAD_SCHEMA = [
        'required' => ['aggregate_type', 'aggregate_id', 'payload'],
        'properties' => [
            'aggregate_type' => ['type' => 'string'],
            'aggregate_id' => ['type' => 'string'],
            'correlation' => ['type' => 'object'],
            'payload' => ['type' => 'object'],
        ],
    ];

    public function run(): void
    {
        $application = Application::query()->where('application_code', self::APPLICATION_CODE)->first();

        if (! $application) {
            $this->command?->warn('Application "'.self::APPLICATION_CODE.'" does not exist yet; register it, then run this seeder again.');

            return;
        }

        foreach (self::EVENTS as $key => $definition) {
            EventCatalogEntry::query()->updateOrCreate(
                ['event_key' => $key],
                [
                    'application_id' => $application->id,
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'schema_version' => '1',
                    'payload_schema' => self::PAYLOAD_SCHEMA,
                    'status' => EventCatalogEntry::STATUS_ACTIVE,
                ],
            );
        }
    }
}
