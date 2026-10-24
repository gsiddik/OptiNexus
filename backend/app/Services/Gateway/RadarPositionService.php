<?php

namespace App\Services\Gateway;

use App\Models\Application;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The latest position of every OptiRadar device of one tenant, for an application that is entitled to OptiRadar for that tenant
 * (K5). The caller never holds an OptiRadar credential and never sees another tenant's devices: the tenant is decided by the
 * service account, and the device list is cut by group on this side. One OptiRadar read is shared between callers for a few
 * seconds, so many tenants polling do not multiply the load on OptiRadar.
 */
class RadarPositionService
{
    private const KNOTS_TO_KMH = 1.852;

    public function __construct(private readonly RadarAccess $radar, private readonly RadarSnapshot $snapshot) {}

    /**
     * @return array{0: ?list<array<string, mixed>>, 1: ?string, 2: ?string} [devices, error code, message]
     */
    public function forTenant(Application $caller, Tenant $tenant): array
    {
        [$application, $refusal] = $this->radar->check($caller, $tenant);
        if ($application === null) {
            return [null, 'RADAR_NOT_ENTITLED', $refusal];
        }

        try {
            $snapshot = Cache::remember('gateway.optiradar.snapshot', max(0, (int) config('gateway.optiradar.positions_cache_seconds')), fn () => $this->snapshot->load());
        } catch (Throwable $e) {
            Log::warning('radar.positions_failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);

            return [null, 'RADAR_UNAVAILABLE', 'OptiRadar did not answer.'];
        }

        $devices = [];
        foreach ($snapshot['devices'] as $device) {
            if ($this->snapshot->tenantOf($snapshot['groups'], $device['groupId'] ?? null) !== $tenant->id) {
                continue;
            }

            $position = $snapshot['positions']->get($device['id']);
            $attributes = $device['attributes'] ?? [];
            $devices[] = [
                'device_ref' => (string) $device['id'],
                'name' => $device['name'] ?? null,
                'registration_number' => $attributes['registrationNumber'] ?? $attributes['plate'] ?? null,
                'status' => $device['status'] ?? 'unknown',
                'position' => $position ? [
                    'latitude' => $position['latitude'] ?? null,
                    'longitude' => $position['longitude'] ?? null,
                    'speed_kmh' => isset($position['speed']) ? round($position['speed'] * self::KNOTS_TO_KMH, 1) : null,
                    'course' => $position['course'] ?? null,
                    'valid' => (bool) ($position['valid'] ?? false),
                    'fix_time' => $position['fixTime'] ?? null,
                ] : null,
            ];
        }

        return [$devices, null, null];
    }
}
