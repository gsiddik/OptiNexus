<?php

namespace App\Services\Gateway;

use App\Models\Application;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pulls the latest odometer of every OptiRadar device and feeds it
 * into the gateway. Tenant isolation follows decision D3: each OptiNexus
 * tenant owns one OptiRadar group (attribute `optinexusTenantId`), and a
 * device belongs to the tenant of its group or the nearest ancestor group
 * that carries the attribute. Devices outside any tenant group are skipped.
 */
class OptiRadarConnector
{
    public function __construct(private readonly GatewayService $gateway, private readonly RadarSnapshot $radar) {}

    /**
     * @return array{tenants: int, devices: int, accepted: int, duplicates: int, skipped: int}
     */
    public function sync(): array
    {
        $config = config('gateway.optiradar');
        if ($config['base_url'] === '' || ! $config['token']) {
            throw new RuntimeException('OptiRadar connector is not configured (OPTIRADAR_BASE_URL / OPTIRADAR_API_TOKEN).');
        }

        ['groups' => $groups, 'devices' => $devices, 'positions' => $positions] = $this->radar->load();

        $subscribed = $this->subscribedTenantIds($config['application_code']);

        $byTenant = [];
        $skipped = 0;

        foreach ($devices as $device) {
            $tenantId = $this->radar->tenantOf($groups, $device['groupId'] ?? null);
            $position = $positions->get($device['id']);
            [$metres, $kind] = $position ? $this->odometerMetres($position) : [null, null];

            if (! $tenantId || ! in_array($tenantId, $subscribed, true) || $metres === null) {
                $skipped++;

                continue;
            }

            $attributes = $device['attributes'] ?? [];
            $byTenant[$tenantId][] = [
                'device_ref' => (string) $device['id'],
                'device_name' => $device['name'] ?? null,
                'registration_number' => $attributes['registrationNumber'] ?? $attributes['plate'] ?? null,
                'odometer_km' => $this->metresToKilometres($metres),
                'odometer_kind' => $kind,
                'recorded_at' => $position['fixTime'] ?? $position['deviceTime'] ?? now()->toIso8601String(),
            ];
        }

        $accepted = $duplicates = 0;
        foreach ($byTenant as $tenantId => $readings) {
            foreach (array_chunk($readings, 500) as $chunk) {
                $result = $this->gateway->ingestReadings($tenantId, $config['application_code'], $chunk);
                $accepted += $result['accepted'];
                $duplicates += $result['duplicates'];
            }
        }

        Log::info('optiradar.sync', ['tenants' => count($byTenant), 'devices' => $devices->count(), 'accepted' => $accepted]);

        return ['tenants' => count($byTenant), 'devices' => $devices->count(), 'accepted' => $accepted, 'duplicates' => $duplicates, 'skipped' => $skipped];
    }

    /** Exact decimal conversion, rounded half-up to 2 places (BCMath truncates, so add half a unit). */
    private function metresToKilometres(string $metres): string
    {
        return bcadd(bcdiv($metres, '1000', 3), '0.005', 2);
    }

    /** Device-reported odometer wins; otherwise the GPS distance accumulated by OptiRadar. */
    private function odometerMetres(array $position): array
    {
        $attributes = $position['attributes'] ?? [];

        foreach (['odometer' => 'DEVICE_ODOMETER', 'totalDistance' => 'GPS_DISTANCE'] as $key => $kind) {
            if (isset($attributes[$key]) && is_numeric($attributes[$key]) && $attributes[$key] >= 0) {
                return [number_format((float) $attributes[$key], 0, '.', ''), $kind];
            }
        }

        return [null, null];
    }

    /** @return array<int, string> */
    private function subscribedTenantIds(string $applicationCode): array
    {
        $application = Application::query()->where('application_code', $applicationCode)->first();
        if (! $application) {
            return [];
        }

        return Tenant::query()
            ->where('status', Tenant::STATUS_ACTIVE)
            ->whereHas('applications', fn ($q) => $q->where('applications.id', $application->id)->where('tenant_applications.status', 'ACTIVE'))
            ->pluck('id')
            ->all();
    }
}
