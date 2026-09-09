<?php

namespace App\Services\FeatureFlag;

use App\Models\FeatureFlag;
use App\Models\FeatureFlagOverride;

/**
 * Deterministic scope precedence: USER > TENANT > APPLICATION > GLOBAL
 * override > GLOBAL DEFAULT. The GLOBAL scope override sits at the same
 * precedence tier as the flag's own default_value (a temporary global
 * kill-switch distinct from the coded default), so it is only consulted
 * once all narrower scopes have no applicable override.
 */
class FeatureFlagEvaluationService
{
    public function evaluate(FeatureFlag $flag, ?string $tenantId, ?string $userId): array
    {
        if (! $flag->isActive()) {
            return ['enabled' => false, 'value' => null, 'source' => 'FLAG_INACTIVE'];
        }

        $candidates = [
            [FeatureFlag::SCOPE_USER, $userId],
            [FeatureFlag::SCOPE_TENANT, $tenantId],
            [FeatureFlag::SCOPE_APPLICATION, $flag->application_id],
            [FeatureFlag::SCOPE_GLOBAL, null],
        ];

        foreach ($candidates as [$scopeType, $scopeId]) {
            if ($scopeType !== FeatureFlag::SCOPE_GLOBAL && ! $scopeId) {
                continue;
            }

            $override = $this->findOverride($flag, $scopeType, $scopeId);
            if ($override) {
                return [
                    'enabled' => $this->toBool($override->value, $flag->flag_type),
                    'value' => $override->value,
                    'source' => $scopeType,
                ];
            }
        }

        return [
            'enabled' => $this->toBool($flag->default_value, $flag->flag_type),
            'value' => $flag->default_value,
            'source' => 'GLOBAL_DEFAULT',
        ];
    }

    private function findOverride(FeatureFlag $flag, string $scopeType, ?string $scopeId): ?FeatureFlagOverride
    {
        $now = now();

        return $flag->overrides()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $now))
            ->first();
    }

    private function toBool(mixed $value, string $flagType): bool
    {
        if ($flagType === FeatureFlag::TYPE_BOOLEAN) {
            return $value === true;
        }

        return $value !== null && $value !== false;
    }
}
