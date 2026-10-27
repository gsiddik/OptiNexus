<?php

namespace Database\Seeders;

/**
 * Registers the events OptiDeli reports to OptiNexus (POST /api/v1/events) in the Event Catalog.
 *
 * Run: php artisan db:seed --class=OptiDeliEventCatalogSeeder
 * The application with code `optideli` must exist first.
 */
class OptiDeliEventCatalogSeeder extends ApplicationEventCatalogSeeder
{
    public const APPLICATION_CODE = 'optideli';

    /** @var array<string, array{name: string, description: string}> */
    public const EVENTS = [
        'optideli.vehicle.upserted' => [
            'name' => 'Vehicle transport data changed',
            'description' => 'A vehicle of the own fleet was created or its transport data changed in OptiDeli (master data sync, docs/integration/MASTER_DATA_SYNC.md). Vendor vehicles are never sent. aggregate_type is vehicle, aggregate_id the vehicle. payload: key, registration_number, version, changed_at (UTC), transport{carrier_type_code, capacity_g, length_mm, width_mm, height_mm}. Only the transport group is for OptiDeli to send.',
        ],
        'optideli.vehicle.driver_assigned' => [
            'name' => 'Driver took a vehicle',
            'description' => 'A trip of the own fleet left (SPM trip or order trip), or a day of a vehicle contract was worked, by a driver of the tenant (master data sync stage 4, docs/integration/MASTER_DATA_SYNC.md). Vendor vehicles and drivers are never sent. aggregate_type is vehicle, aggregate_id the vehicle. payload: key, registration_number, assigned_at (UTC), reference{type SPM_TRIP|ORDER_TRIP|ATTENDANCE, id, number}, driver{name, phone, license_masked (last four characters only)}. The licence number, date of birth and address never leave OptiDeli.',
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
