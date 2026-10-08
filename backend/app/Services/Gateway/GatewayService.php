<?php

namespace App\Services\Gateway;

use App\Models\GatewayFleetVehicle;
use App\Models\GatewayOdometerReading;
use App\Models\GatewayVehicleLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Core of the API Gateway data exchange: fleet vehicle directory, device
 * links and the odometer feed. Every method is tenant-scoped; the tenant is
 * always resolved by the gateway middleware, never read from a payload.
 */
class GatewayService
{
    /**
     * Upserts the vehicle directory published by a fleet application and
     * links any telematics devices waiting for a matching registration number.
     *
     * @param  array<int, array{id: string, registration_number: string, vin?: ?string, status?: ?string}>  $vehicles
     * @return array{upserted: int, linked: int}
     */
    public function publishVehicles(string $tenantId, string $applicationId, array $vehicles): array
    {
        return DB::transaction(function () use ($tenantId, $applicationId, $vehicles) {
            foreach ($vehicles as $vehicle) {
                GatewayFleetVehicle::query()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'application_id' => $applicationId, 'external_vehicle_id' => (string) $vehicle['id']],
                    [
                        'registration_number' => $vehicle['registration_number'],
                        'normalized_registration' => GatewayFleetVehicle::normalizeRegistration($vehicle['registration_number']),
                        'vin' => $vehicle['vin'] ?? null,
                        'status' => $vehicle['status'] ?? 'ACTIVE',
                    ],
                );
            }

            return ['upserted' => count($vehicles), 'linked' => $this->autoLinkPending($tenantId)];
        });
    }

    /**
     * Stores readings idempotently. Unknown devices become UNMATCHED links
     * (then auto-linked by registration when a vehicle exists), so no reading
     * is lost while an admin sorts out the mapping.
     *
     * @param  array<int, array{device_ref: string, odometer_km: numeric-string|int|float, recorded_at: string, device_name?: ?string, registration_number?: ?string, odometer_kind?: ?string}>  $readings
     * @return array{accepted: int, duplicates: int}
     */
    public function ingestReadings(string $tenantId, string $source, array $readings): array
    {
        $accepted = 0;
        $duplicates = 0;

        DB::transaction(function () use ($tenantId, $source, $readings, &$accepted, &$duplicates) {
            foreach ($readings as $reading) {
                $link = $this->touchLink($tenantId, $source, $reading);

                $recordedAt = Carbon::parse($reading['recorded_at'])->utc();
                $exists = GatewayOdometerReading::query()
                    ->where(['tenant_id' => $tenantId, 'source' => $source, 'device_ref' => (string) $reading['device_ref'], 'recorded_at' => $recordedAt])
                    ->exists();

                if ($exists) {
                    $duplicates++;

                    continue;
                }

                GatewayOdometerReading::create([
                    'seq' => $this->nextSeq(),
                    'reading_id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'source' => $source,
                    'device_ref' => (string) $reading['device_ref'],
                    // Decimal string end to end: monetary-grade safety, no float rounding of kilometres.
                    'odometer_km' => number_format((float) $reading['odometer_km'], 2, '.', ''),
                    'odometer_kind' => $reading['odometer_kind'] ?? 'DEVICE_ODOMETER',
                    'recorded_at' => $recordedAt,
                ]);
                $accepted++;
            }
        });

        return ['accepted' => $accepted, 'duplicates' => $duplicates];
    }

    /**
     * Readings of linked devices for one fleet application, after a cursor.
     *
     * @return array{items: array<int, array<string, mixed>>, next_cursor: string}
     */
    public function feed(string $tenantId, string $applicationId, int $cursor, int $limit): array
    {
        $rows = DB::table('gateway_odometer_readings as r')
            ->join('gateway_vehicle_links as l', function ($join) {
                $join->on('l.tenant_id', '=', 'r.tenant_id')
                    ->on('l.telematics_source', '=', 'r.source')
                    ->on('l.device_ref', '=', 'r.device_ref');
            })
            ->join('gateway_fleet_vehicles as v', 'v.id', '=', 'l.fleet_vehicle_id')
            ->where('r.tenant_id', $tenantId)
            ->where('l.status', GatewayVehicleLink::STATUS_ACTIVE)
            ->where('v.application_id', $applicationId)
            ->where('r.seq', '>', $cursor)
            ->orderBy('r.seq')
            ->limit($limit)
            ->get(['r.seq', 'r.reading_id', 'r.source', 'r.device_ref', 'r.odometer_km', 'r.odometer_kind', 'r.recorded_at', 'v.external_vehicle_id', 'v.registration_number']);

        $items = $rows->map(fn ($row) => [
            'reading_id' => $row->reading_id,
            'vehicle_id' => $row->external_vehicle_id,
            'registration_number' => $row->registration_number,
            'odometer_km' => $row->odometer_km,
            'odometer_kind' => $row->odometer_kind,
            'recorded_at' => Carbon::parse($row->recorded_at, 'UTC')->toIso8601String(),
            'source' => $row->source,
            'device_ref' => $row->device_ref,
        ])->all();

        return ['items' => $items, 'next_cursor' => (string) ($rows->isEmpty() ? $cursor : $rows->last()->seq)];
    }

    /**
     * Manually links (or relinks) a device to a fleet vehicle. Manual links
     * always win over automatic ones.
     */
    public function linkManually(string $tenantId, string $linkId, string $fleetVehicleId, ?string $userId): GatewayVehicleLink
    {
        return DB::transaction(function () use ($tenantId, $linkId, $fleetVehicleId, $userId) {
            $link = GatewayVehicleLink::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($linkId);
            $vehicle = GatewayFleetVehicle::query()->where('tenant_id', $tenantId)->findOrFail($fleetVehicleId);

            $link->forceFill([
                'fleet_vehicle_id' => $vehicle->id,
                'link_type' => GatewayVehicleLink::TYPE_MANUAL,
                'status' => GatewayVehicleLink::STATUS_ACTIVE,
                'created_by' => $userId,
            ])->save();

            $this->reemitLatest($link);

            return $link;
        });
    }

    public function unlink(string $tenantId, string $linkId): GatewayVehicleLink
    {
        $link = GatewayVehicleLink::query()->where('tenant_id', $tenantId)->findOrFail($linkId);
        // Keep the row (as MANUAL + INACTIVE) so auto-linking never silently re-attaches it.
        $link->forceFill(['status' => GatewayVehicleLink::STATUS_INACTIVE, 'link_type' => GatewayVehicleLink::TYPE_MANUAL])->save();

        return $link;
    }

    public function autoLinkPending(string $tenantId): int
    {
        $linked = 0;

        GatewayVehicleLink::query()
            ->where('tenant_id', $tenantId)
            ->where('status', GatewayVehicleLink::STATUS_UNMATCHED)
            ->where('link_type', GatewayVehicleLink::TYPE_AUTO)
            ->get()
            ->each(function (GatewayVehicleLink $link) use ($tenantId, &$linked) {
                $vehicle = $this->matchVehicle($tenantId, $link->device_registration, $link->device_name);
                if ($vehicle) {
                    $link->forceFill(['fleet_vehicle_id' => $vehicle->id, 'status' => GatewayVehicleLink::STATUS_ACTIVE])->save();
                    $this->reemitLatest($link);
                    $linked++;
                }
            });

        return $linked;
    }

    private function touchLink(string $tenantId, string $source, array $reading): GatewayVehicleLink
    {
        $deviceRef = (string) $reading['device_ref'];

        $link = GatewayVehicleLink::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'telematics_source' => $source, 'device_ref' => $deviceRef],
            [
                'device_name' => $reading['device_name'] ?? null,
                'device_registration' => $reading['registration_number'] ?? null,
                'link_type' => GatewayVehicleLink::TYPE_AUTO,
                'status' => GatewayVehicleLink::STATUS_UNMATCHED,
            ],
        );

        if ($link->link_type === GatewayVehicleLink::TYPE_AUTO) {
            $link->fill(array_filter([
                'device_name' => $reading['device_name'] ?? null,
                'device_registration' => $reading['registration_number'] ?? null,
            ]));

            if ($link->status === GatewayVehicleLink::STATUS_UNMATCHED && ($vehicle = $this->matchVehicle($tenantId, $link->device_registration, $link->device_name))) {
                $link->fill(['fleet_vehicle_id' => $vehicle->id, 'status' => GatewayVehicleLink::STATUS_ACTIVE]);
            }

            $link->save();
        }

        return $link;
    }

    private function matchVehicle(string $tenantId, ?string $registration, ?string $deviceName): ?GatewayFleetVehicle
    {
        foreach ([$registration, $deviceName] as $candidate) {
            $normalized = GatewayFleetVehicle::normalizeRegistration($candidate);
            if ($normalized === '') {
                continue;
            }

            $matches = GatewayFleetVehicle::query()
                ->where('tenant_id', $tenantId)
                ->where('normalized_registration', $normalized)
                ->limit(2)
                ->get();

            // Ambiguous plates (two vehicles) are left for an admin instead of guessing.
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    private function reemitLatest(GatewayVehicleLink $link): void
    {
        $latest = GatewayOdometerReading::query()
            ->where(['tenant_id' => $link->tenant_id, 'source' => $link->telematics_source, 'device_ref' => $link->device_ref])
            ->orderByDesc('recorded_at')
            ->first();

        $latest?->forceFill(['seq' => $this->nextSeq()])->save();
    }

    private function nextSeq(): int
    {
        return (int) DB::selectOne("SELECT nextval('gateway_odometer_seq') AS seq")->seq;
    }
}
