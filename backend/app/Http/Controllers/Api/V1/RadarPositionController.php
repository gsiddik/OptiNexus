<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Services\Gateway\RadarPositionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RadarPositionController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly RadarPositionService $positions) {}

    /** Machine-to-machine (scope `positions.read`, tenant bound): the latest OptiRadar positions of one tenant. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['tenant_id' => ['required', 'uuid']]);

        /** @var ServiceAccount $account */
        $account = $request->attributes->get('service_account');
        $caller = $account->application;
        if (! $caller) {
            return $this->fail('SERVICE_ACCOUNT_NOT_BOUND', 'This service account is not bound to an application.', 403);
        }

        $tenant = Tenant::query()->find($request->input('tenant_id'));
        if (! $tenant) {
            return $this->fail('TENANT_NOT_FOUND', 'Unknown tenant.', 404);
        }

        [$devices, $code, $message] = $this->positions->forTenant($caller, $tenant);
        if ($devices === null) {
            return $this->fail($code, $message, $code === 'RADAR_UNAVAILABLE' ? 502 : 403);
        }

        return $this->ok($devices, ['count' => count($devices)]);
    }
}
