<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Ecosystem\OnboardingReportService;
use Illuminate\Http\JsonResponse;

/** Read-only onboarding / preflight report for one tenant (audit A14); ecosystem operators only. */
class EcosystemOnboardingController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly OnboardingReportService $onboarding) {}

    public function show(Tenant $tenant): JsonResponse
    {
        return $this->ok($this->onboarding->report($tenant));
    }
}
