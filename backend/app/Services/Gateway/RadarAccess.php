<?php

namespace App\Services\Gateway;

use App\Models\Application;
use App\Models\Tenant;
use App\Services\Commercial\SubscriptionAccess;

/**
 * Whether an application may use OptiRadar data for a tenant (embed sessions, positions): OptiRadar is published, the tenant is
 * active, and the tenant holds an active subscription to both the caller and OptiRadar.
 */
class RadarAccess
{
    public function __construct(private readonly SubscriptionAccess $access) {}

    /** @return array{0: ?Application, 1: ?string} [the OptiRadar application, a refusal message] */
    public function check(Application $caller, Tenant $tenant): array
    {
        $radar = Application::query()->where('application_code', config('gateway.optiradar.application_code'))->first();
        if (! $radar || ! $radar->isPublished() || ! $tenant->isActive()) {
            return [null, 'The tenant cannot use OptiRadar.'];
        }

        foreach ([$caller, $radar] as $app) {
            if (! $tenant->applications()->where('applications.id', $app->id)->wherePivot('status', 'ACTIVE')->exists() || ! $this->access->allowed($tenant, $app)) {
                return [null, 'The tenant is not entitled to '.$app->application_code.'.'];
            }
        }

        return [$radar, null];
    }
}
