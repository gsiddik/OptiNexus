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
        'optideli.fleet_vehicle.upserted' => [
            'name' => 'Own vehicle for the tracker platform',
            'description' => 'A vehicle of the own fleet with its transport data complete was created or changed in OptiDeli (master data sync, docs/integration/MASTER_DATA_SYNC.md). For OptiRadar only, which makes a device for it; OptiFleet does not subscribe (it has optideli.vehicle.upserted). aggregate_type is vehicle, aggregate_id the vehicle. payload: key, registration_number, version, changed_at (UTC), active, tracker_id (the identifier the tracker reports, may be null), transport{carrier_type_code, capacity_g, length_mm, width_mm, height_mm}.',
        ],
        'optideli.vendor_vehicle.upserted' => [
            'name' => 'Vendor vehicle verified or changed',
            'description' => 'A vehicle of a vendor was verified by the tenant, or (de)activated (master data sync stage 4). For OptiRadar only: a vendor vehicle may carry a tracker to monitor. OptiFleet does not subscribe, the tenant does not maintain a vendor vehicle. aggregate_type is vehicle, aggregate_id the vehicle. payload: key, registration_number, version, changed_at (UTC), active, tracker_id, vendor{code, name}, transport{carrier_type_code, capacity_g, length_mm, width_mm, height_mm}.',
        ],
        'optideli.vendor_driver.upserted' => [
            'name' => 'Vendor driver verified or changed',
            'description' => 'A driver of a vendor was verified by the tenant, or (de)activated (master data sync stage 4). For OptiRadar only. aggregate_type is driver, aggregate_id the driver. payload: key (normalised licence number), license_number, name, version, changed_at (UTC), active, vendor{code, name}.',
        ],
        'optideli.pickup.status_changed' => [
            'name' => 'Pickup request status changed',
            'description' => 'What happened to a pickup request OptiStash sent (docs/integration/PICKUP_REQUESTS.md in OptiDeli). For OptiStash only. aggregate_type is shipment, aggregate_id the pickup request. payload: shipment_number (the OptiStash shipment), status (ACCEPTED, REJECTED, PICKED_UP, DELIVERED or CANCELLED), occurred_at (UTC), reason (a rejection only). The SPM, its prices and its internal reasons never leave OptiDeli.',
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
