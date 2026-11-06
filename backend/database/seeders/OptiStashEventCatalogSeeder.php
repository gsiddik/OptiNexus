<?php

namespace Database\Seeders;

/**
 * Registers the events OptiStash reports to OptiNexus (POST /api/v1/events) in the Event Catalog.
 *
 * Run: php artisan db:seed --class=OptiStashEventCatalogSeeder
 * The application with code `optistash` must exist first (EcosystemCatalogSeeder imports it from its manifest).
 */
class OptiStashEventCatalogSeeder extends ApplicationEventCatalogSeeder
{
    public const APPLICATION_CODE = 'optistash';

    /** @var array<string, array{name: string, description: string}> */
    public const EVENTS = [
        'optistash.pickup.requested' => [
            'name' => 'Goods ready for pickup',
            'description' => 'A shipment left the warehouse queue: its orders are packed and a carrier has to collect them. Sent only for tenants whose transport mode is OPTIDELI. For OptiDeli, which turns it into a pickup job. aggregate_type is shipment, aggregate_id the shipment. payload: shipment_number, warehouse{code, name, address, city} (the place only, never its zones, aisles, racks or bins), items[]{product_code, product_name, quantity (base unit), uom}. Customers, prices and stock locations never leave OptiStash.',
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
