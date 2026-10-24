<?php

namespace App\Services\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One read of OptiRadar with the read-only sync token (groups, devices, latest positions), and the rule that decides which tenant a
 * device belongs to (decision D3: the group, or the nearest ancestor group, that carries the tenant attribute).
 */
class RadarSnapshot
{
    /** @return array{groups: Collection, devices: Collection, positions: Collection} */
    public function load(): array
    {
        $http = $this->client();

        return [
            'groups' => collect($http->get('/api/groups')->throw()->json())->keyBy('id'),
            'devices' => collect($http->get('/api/devices')->throw()->json()),
            'positions' => collect($http->get('/api/positions')->throw()->json())->keyBy('deviceId'),
        ];
    }

    public function tenantOf(Collection $groups, ?int $groupId): ?string
    {
        $attribute = config('gateway.optiradar.tenant_group_attribute');
        $seen = [];
        while ($groupId && ! isset($seen[$groupId])) {
            $seen[$groupId] = true;
            $group = $groups->get($groupId);
            if (! $group) {
                return null;
            }

            if (! empty($group['attributes'][$attribute])) {
                return (string) $group['attributes'][$attribute];
            }

            $groupId = $group['groupId'] ?? null;
        }

        return null;
    }

    private function client(): PendingRequest
    {
        $config = config('gateway.optiradar');
        if ($config['base_url'] === '' || ! $config['token']) {
            throw new RuntimeException('OptiRadar connector is not configured (OPTIRADAR_BASE_URL / OPTIRADAR_API_TOKEN).');
        }

        return Http::withToken($config['token'])->acceptJson()->timeout($config['timeout_seconds'])->baseUrl($config['base_url']);
    }
}
