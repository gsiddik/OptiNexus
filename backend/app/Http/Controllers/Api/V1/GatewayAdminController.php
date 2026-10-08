<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\GatewayFleetVehicle;
use App\Models\GatewayRequestLog;
use App\Models\GatewayVehicleLink;
use App\Models\Tenant;
use App\Services\AuditService;
use App\Services\Gateway\GatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Human-admin view of the gateway for one tenant: the vehicle <-> device
 * mapping (including unmatched devices) and recent gateway calls.
 */
class GatewayAdminController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly GatewayService $gateway,
        private readonly AuditService $audit,
    ) {}

    public function links(Request $request, Tenant $tenant): JsonResponse
    {
        $query = GatewayVehicleLink::query()->where('tenant_id', $tenant->id)->with('vehicle');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $this->ok($query->orderBy('created_at')->get()->map(fn (GatewayVehicleLink $l) => $this->presentLink($l)));
    }

    public function vehicles(Tenant $tenant): JsonResponse
    {
        return $this->ok(GatewayFleetVehicle::query()->where('tenant_id', $tenant->id)->orderBy('registration_number')->get());
    }

    public function link(Request $request, Tenant $tenant, GatewayVehicleLink $link): JsonResponse
    {
        abort_unless($link->tenant_id === $tenant->id, 404);

        $data = $request->validate(['fleet_vehicle_id' => ['required', 'uuid']]);
        $link = $this->gateway->linkManually($tenant->id, $link->id, $data['fleet_vehicle_id'], $request->user()?->id);

        $this->audit->record('gateway.link.manual', $request, tenantId: $tenant->id, resourceType: 'GatewayVehicleLink', resourceId: $link->id, newValue: ['fleet_vehicle_id' => $link->fleet_vehicle_id, 'device_ref' => $link->device_ref]);

        return $this->ok($this->presentLink($link->load('vehicle')));
    }

    public function unlink(Request $request, Tenant $tenant, GatewayVehicleLink $link): JsonResponse
    {
        abort_unless($link->tenant_id === $tenant->id, 404);

        $link = $this->gateway->unlink($tenant->id, $link->id);
        $this->audit->record('gateway.link.removed', $request, tenantId: $tenant->id, resourceType: 'GatewayVehicleLink', resourceId: $link->id);

        return $this->ok($this->presentLink($link->load('vehicle')));
    }

    public function logs(Request $request, Tenant $tenant): JsonResponse
    {
        $paginator = GatewayRequestLog::query()->where('tenant_id', $tenant->id)->orderByDesc('created_at')->paginate((int) $request->query('per_page', 50));

        return $this->paginated($paginator);
    }

    private function presentLink(GatewayVehicleLink $link): array
    {
        return [
            'id' => $link->id,
            'telematics_source' => $link->telematics_source,
            'device_ref' => $link->device_ref,
            'device_name' => $link->device_name,
            'device_registration' => $link->device_registration,
            'link_type' => $link->link_type,
            'status' => $link->status,
            'fleet_vehicle_id' => $link->fleet_vehicle_id,
            'fleet_vehicle' => $link->vehicle ? ['external_vehicle_id' => $link->vehicle->external_vehicle_id, 'registration_number' => $link->vehicle->registration_number] : null,
        ];
    }
}
