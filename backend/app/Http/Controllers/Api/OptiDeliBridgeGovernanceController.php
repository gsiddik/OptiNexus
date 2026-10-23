<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class OptiDeliBridgeGovernanceController extends Controller
{
    /**
     * Mengambil konfigurasi jembatan integrasi OptiDeli untuk tenant tertentu.
     */
    public function getBridges(Request $request, string $tenantId): JsonResponse
    {
        $cacheKey = sprintf('optideli_bridges_%s', $tenantId);

        $defaultBridges = [
            'fleet' => [
                'enabled' => true,
                'base_url' => env('OPTIFLEET_API_URL', 'http://optifleet.local:8001'),
                'timeout_seconds' => 3,
            ],
            'telematics' => [
                'enabled' => false,
                'base_url' => env('OPTIRADAR_API_URL', 'http://optiradar.local:8002'),
                'timeout_seconds' => 3,
            ],
            'accounting' => [
                'enabled' => true,
                'base_url' => env('OPTIACCOUNTING_API_URL', 'http://optiaccounting.local:8003'),
                'timeout_seconds' => 3,
            ],
        ];

        $bridges = Cache::get($cacheKey, $defaultBridges);

        return response()->json([
            'data' => [
                'tenant_id' => $tenantId,
                'application' => 'optideli',
                'bridges' => $bridges,
            ],
        ]);
    }

    /**
     * Memperbarui status toggle jembatan integrasi (Aktifkan / Nonaktifkan Bridge).
     */
    public function updateBridges(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'bridges' => 'required|array',
            'bridges.fleet.enabled' => 'nullable|boolean',
            'bridges.fleet.base_url' => 'nullable|string',
            'bridges.telematics.enabled' => 'nullable|boolean',
            'bridges.telematics.base_url' => 'nullable|string',
            'bridges.accounting.enabled' => 'nullable|boolean',
            'bridges.accounting.base_url' => 'nullable|string',
        ]);

        $cacheKey = sprintf('optideli_bridges_%s', $tenantId);
        Cache::forever($cacheKey, $validated['bridges']);

        return response()->json([
            'message' => 'Konfigurasi jembatan OptiDeli berhasil diperbarui di OptiNexus.',
            'data' => [
                'tenant_id' => $tenantId,
                'bridges' => $validated['bridges'],
            ],
        ]);
    }
}
