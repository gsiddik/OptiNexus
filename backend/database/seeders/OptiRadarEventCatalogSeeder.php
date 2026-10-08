<?php

namespace Database\Seeders;

/**
 * Registers the events OptiRadar (GPS telematics) reports to OptiNexus
 * (POST /api/v1/events) in the Event Catalog; the envelope is described in
 * the base class. Here aggregate_type is always `device`, aggregate_id the
 * OptiRadar device id and correlation carries the device's unique id.
 * Distances and speeds are plain numbers: speeds in km/h, coordinates in
 * decimal degrees, times as ISO 8601 UTC strings.
 *
 * Run: php artisan db:seed --class=OptiRadarEventCatalogSeeder
 * The application with code `optiradar` must exist first (OptiRadarApplicationSeeder).
 */
class OptiRadarEventCatalogSeeder extends ApplicationEventCatalogSeeder
{
    public const APPLICATION_CODE = 'optiradar';

    /** @var array<string, array{name: string, description: string}> */
    public const EVENTS = [
        'optiradar.device.online' => [
            'name' => 'Device online',
            'description' => 'A tracker connected and reports again. payload: device_id, device_name, unique_id, status (online), event_time. OptiRadar only sends it when event.status.enable is on.',
        ],
        'optiradar.device.offline' => [
            'name' => 'Device offline',
            'description' => 'A tracker is no longer reporting: its connection closed (status offline) or it sent no data within the status timeout (status unknown). payload: device_id, device_name, unique_id, status, event_time. OptiRadar only sends it when event.status.enable is on.',
        ],
        'optiradar.geofence.entered' => [
            'name' => 'Geofence entered',
            'description' => 'A device entered a geofence. payload: device_id, device_name, unique_id, geofence_id, geofence_name, latitude, longitude, speed_kmh, fix_time, event_time.',
        ],
        'optiradar.geofence.exited' => [
            'name' => 'Geofence exited',
            'description' => 'A device left a geofence. payload: device_id, device_name, unique_id, geofence_id, geofence_name, latitude, longitude, speed_kmh, fix_time, event_time.',
        ],
        'optiradar.device.overspeed' => [
            'name' => 'Device overspeed',
            'description' => 'A device kept above its speed limit for longer than the minimal duration. payload: device_id, device_name, unique_id, speed_kmh, speed_limit_kmh, latitude, longitude, fix_time, event_time, and geofence_id and geofence_name when a geofence limit applied.',
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
