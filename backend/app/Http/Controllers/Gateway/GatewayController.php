<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\GatewayFleetVehicle;
use App\Models\GatewayVehicleLink;
use App\Models\ServiceAccount;
use App\Services\Gateway\GatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Machine-to-machine API Gateway (/api/gateway/v1). Auth and tenant are
 * resolved by the `service_account` and `gateway_tenant` middleware; this
 * controller never reads a tenant from the payload.
 */
class GatewayController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly GatewayService $gateway) {}

    public function publishVehicles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicles' => ['required', 'array', 'min:1', 'max:1000'],
            'vehicles.*.id' => ['required', 'string', 'max:64'],
            'vehicles.*.registration_number' => ['required', 'string', 'max:32'],
            'vehicles.*.vin' => ['nullable', 'string', 'max:32'],
            'vehicles.*.status' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->ok($this->gateway->publishVehicles($this->tenantId($request), $this->account($request)->application_id, $data['vehicles']));
    }

    public function vehicleLinks(Request $request): JsonResponse
    {
        $links = GatewayVehicleLink::query()
            ->where('tenant_id', $this->tenantId($request))
            ->whereHas('vehicle', fn ($q) => $q->where('application_id', $this->account($request)->application_id))
            ->with('vehicle')
            ->orderBy('created_at')
            ->get()
            ->map(fn (GatewayVehicleLink $link) => [
                'vehicle_id' => $link->vehicle->external_vehicle_id,
                'registration_number' => $link->vehicle->registration_number,
                'telematics_source' => $link->telematics_source,
                'device_ref' => $link->device_ref,
                'device_name' => $link->device_name,
                'link_type' => $link->link_type,
                'status' => $link->status,
            ]);

        return $this->ok($links);
    }

    public function ingestReadings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'readings' => ['required', 'array', 'min:1', 'max:1000'],
            'readings.*.device_ref' => ['required', 'string', 'max:64'],
            'readings.*.odometer_km' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'readings.*.recorded_at' => ['required', 'date'],
            'readings.*.device_name' => ['nullable', 'string', 'max:255'],
            'readings.*.registration_number' => ['nullable', 'string', 'max:32'],
        ]);

        $source = $this->account($request)->application->application_code;

        return $this->ok($this->gateway->ingestReadings($this->tenantId($request), $source, $data['readings']), status: 202);
    }

    public function readings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return $this->ok($this->gateway->feed(
            $this->tenantId($request),
            $this->account($request)->application_id,
            (int) ($validated['cursor'] ?? 0),
            (int) ($validated['limit'] ?? 200),
        ));
    }

    private function tenantId(Request $request): string
    {
        return $request->attributes->get('gateway_tenant_id');
    }

    private function account(Request $request): ServiceAccount
    {
        return $request->attributes->get('service_account');
    }
}
